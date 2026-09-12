<?php

use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Contracts\NodeExecutionStore;
use Heiner\AgentGraph\Contracts\RunStore;
use Heiner\AgentGraph\Graph\GraphDefinition;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\ExecutionFrontier;
use Heiner\AgentGraph\Runtime\GraphRuntime;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunResult;
use Heiner\AgentGraph\Runtime\RuntimeOptions;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
    foreach (['Run', 'Checkpoint', 'Interrupt', 'NodeExecution', 'Write', 'Task', 'Memory', 'Trace'] as $store) {
        app()->singleton('Heiner\\AgentGraph\\Contracts\\'.$store.'Store', 'Heiner\\AgentGraph\\Persistence\\Database'.$store.'Store');
    }
    app()->singleton(GraphRuntime::class, ResumeProofCrashRuntime::class);
});

it('recovers an identical input or state edit from either accepted proof', function (string $type, string $phase) {
    [$manager, $waiting, $probe, $resume] = resumeProofFixture($type, $phase);
    $result = $resume();
    expect($result->completed())->toBeTrue()->and($result->state('answer'))->toBe('accepted')
        ->and($probe->effects)->toBe(['accepted']);
    expect($manager->recover($waiting->runId())->completed())->toBeTrue()
        ->and($probe->effects)->toBe(['accepted']);
})->with(['input', 'state_edit'])->with(['accepted', 'scheduled']);

it('rejects changed resolved responses before recovering either input or state edit proof', function (string $type, string $phase) {
    [$manager, $waiting, $probe] = resumeProofFixture($type, $phase);
    $interruptId = $waiting->interrupt()['interrupt_id'];
    $response = app(InterruptStore::class)->find($interruptId)['response'];
    data_set($response, $type === 'state_edit' ? 'state.answer' : 'answer', 'foreign');
    app(InterruptStore::class)->resolve($interruptId, $response);
    $before = resumeProofSnapshot();

    expect(fn () => $manager->recover($waiting->runId()))->toThrow(RuntimeException::class, 'response changed');
    expect(resumeProofSnapshot())->toBe($before)->and($probe->effects)->toBe([]);
})->with(['input', 'state_edit'])->with(['accepted', 'scheduled']);

it('rejects inconsistent acceptance metadata before scheduling', function (string $field, mixed $value) {
    [$manager, $waiting, $probe] = resumeProofFixture('input', 'accepted');
    $runs = app(RunStore::class);
    $meta = $runs->find($waiting->runId())['meta'];
    data_set($meta, 'runtime.recovery.pending_resume.'.$field, $value);
    $runs->update($waiting->runId(), ['meta' => $meta]);
    $before = resumeProofSnapshot();

    expect(fn () => $manager->recover($waiting->runId()))->toThrow(RuntimeException::class);
    expect(resumeProofSnapshot())->toBe($before)->and($probe->effects)->toBe([]);
})->with([
    'hash' => ['resume_payload_hash', 'foreign'],
    'payload' => ['resume_payload', ['answer' => 'foreign']],
    'step' => ['step', 23],
    'kind' => ['kind', 'foreign'],
    'schedule target' => ['schedule', [['node' => 'effect', 'input' => [], 'meta' => []]]],
    'schedule input' => ['schedule', [['node' => 'ask', 'input' => ['answer' => 'foreign'], 'meta' => []]]],
    'schedule metadata' => ['schedule', [['node' => 'ask', 'input' => [], 'meta' => ['foreign' => true]]]],
]);

it('validates receipt authority before recovery delivery or commit mutates persistence', function (string $entrypoint, string $field, mixed $value) {
    [$manager, $waiting, $probe] = resumeProofFixture('input', 'scheduled');
    $execution = app(NodeExecutionStore::class)->listForRunStep($waiting->runId(), 2)[0];
    app('db')->table('agent_graph_node_executions')->where('execution_id', $execution['execution_id'])->update([
        $field => in_array($field, ['base_state', 'node_state', 'meta'], true) ? json_encode($value, JSON_THROW_ON_ERROR) : $value,
    ]);
    $before = resumeProofSnapshot();
    $runtime = app(GraphRuntime::class);
    $invoke = match ($entrypoint) {
        'recover' => fn () => $manager->recover($waiting->runId()),
        'deliver' => fn () => $runtime->executeQueuedNode($execution['execution_id'], $manager->definitions()),
        'commit' => fn () => $runtime->continueQueuedSuperstep($waiting->runId(), 2, $manager->definitions()),
    };

    expect($invoke)->toThrow(RuntimeException::class);
    expect(resumeProofSnapshot())->toBe($before)->and($probe->effects)->toBe([]);
})->with(['recover', 'deliver', 'commit'])->with([
    'checkpoint' => ['checkpoint_id', 'chk_foreign'],
    'interrupt' => ['interrupt_id', 'int_foreign'],
    'node' => ['node_id', 'effect'],
    'base state' => ['base_state', ['answer' => 'foreign']],
    'node state' => ['node_state', ['answer' => 'foreign']],
    'schedule' => ['meta', ['schedule' => ['node' => 'ask', 'input' => ['answer' => 'foreign'], 'meta' => []]]],
]);

it('rejects recovery through resume with options before changing run metadata', function () {
    [$manager, $waiting, $probe] = resumeProofFixture('input', 'scheduled');
    $interruptId = $waiting->interrupt()['interrupt_id'];
    app(InterruptStore::class)->resolve($interruptId, ['interrupt_id' => $interruptId, 'answer' => 'foreign']);
    $before = resumeProofSnapshot();
    expect(fn () => app(GraphRuntime::class)->resume($waiting->runId(), [], $manager->definitions(), options: ['max_steps' => 10]))
        ->toThrow(RuntimeException::class);
    expect(resumeProofSnapshot())->toBe($before)->and($probe->effects)->toBe([]);
});

function resumeProofFixture(string $type, string $phase): array
{
    $manager = app(AgentGraphManager::class);
    $probe = (object) ['effects' => []];
    $manager->define(StateGraph::make('resume-proof')
        ->state(['answer' => 'string|null'])
        ->node('ask', function (NodeContext $context) use ($probe, $type): NodeResult {
            if (! $context->hasResumePayload()) {
                return NodeResult::interrupt($type, []);
            }
            $probe->effects[] = $context->state('answer');

            return NodeResult::end();
        })
        ->node('effect', function () use ($probe): NodeResult {
            $probe->effects[] = 'unexpected';

            return NodeResult::end();
        })
        ->edge(StateGraph::START, 'ask'));
    $waiting = $manager->graph('resume-proof')->run();
    $resume = $type === 'state_edit'
        ? fn () => $manager->resumeWithStateEdit($waiting->runId(), $waiting->interrupt()['interrupt_id'], ['answer' => 'accepted'])
        : fn () => $manager->resume($waiting->runId(), ['interrupt_id' => $waiting->interrupt()['interrupt_id'], 'answer' => 'accepted']);
    app(GraphRuntime::class)->stopAt = $phase;
    config(['agent-graph.execution.mode' => 'queued_supersteps']);
    expect($resume)->toThrow(RuntimeException::class, 'Injected loss after '.$phase);
    app(GraphRuntime::class)->stopAt = null;
    config(['agent-graph.execution.mode' => 'sync']);

    return [$manager, $waiting, $probe, $resume];
}

function resumeProofSnapshot(): array
{
    $snapshot = [];
    foreach (['runs', 'checkpoints', 'interrupts', 'node_executions', 'writes', 'tasks', 'traces'] as $table) {
        $snapshot[$table] = app('db')->table('agent_graph_'.$table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    return $snapshot;
}

final class ResumeProofCrashRuntime extends GraphRuntime
{
    public ?string $stopAt = null;

    protected function prepareContinuationLocked(GraphDefinition $graph, array $run, array $state, array $nextNodes, array $resumeContext = [], ?RuntimeOptions $options = null): RunResult|ExecutionFrontier
    {
        if ($this->stopAt === 'accepted') {
            throw new RuntimeException('Injected loss after accepted');
        }

        return parent::prepareContinuationLocked($graph, $run, $state, $nextNodes, $resumeContext, $options);
    }

    protected function dispatchNodeExecution(string $executionId): void
    {
        if ($this->stopAt === 'scheduled') {
            throw new RuntimeException('Injected loss after scheduled');
        }
        parent::dispatchNodeExecution($executionId);
    }
}
