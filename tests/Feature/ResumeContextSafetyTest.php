<?php

use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Events\GraphCheckpointCreated;
use Heiner\AgentGraph\Events\GraphResumed;
use Heiner\AgentGraph\Graph\InterruptPolicy;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Persistence\DatabaseCheckpointStore;
use Heiner\AgentGraph\Persistence\DatabaseInterruptStore;
use Heiner\AgentGraph\Persistence\DatabaseMemoryStore;
use Heiner\AgentGraph\Persistence\DatabaseNodeExecutionStore;
use Heiner\AgentGraph\Persistence\DatabaseRunStore;
use Heiner\AgentGraph\Persistence\DatabaseTaskStore;
use Heiner\AgentGraph\Persistence\DatabaseTraceStore;
use Heiner\AgentGraph\Persistence\DatabaseWriteStore;
use Heiner\AgentGraph\Runtime\GraphRuntime;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunResult;
use Illuminate\Support\Facades\Queue;

// Explicit database stores avoid Testbench's automatic in-memory store bindings.

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
});

function resumeSafetyManager(): AgentGraphManager
{
    $db = app('db');

    return new AgentGraphManager(new GraphRuntime(
        container: app(),
        runs: new DatabaseRunStore($db),
        checkpoints: new DatabaseCheckpointStore($db),
        writes: new DatabaseWriteStore($db),
        tasks: new DatabaseTaskStore($db),
        interrupts: new DatabaseInterruptStore($db),
        memory: new DatabaseMemoryStore($db),
        traces: new DatabaseTraceStore($db),
        locks: app(LockProvider::class),
        nodeExecutions: new DatabaseNodeExecutionStore($db),
    ));
}

it('rejects an explicitly expired approval even when interrupt_id is omitted', function (string $method) {
    $manager = resumeSafetyManager();
    $effects = 0;
    $manager->define(StateGraph::make('expired_approval')
        ->state(['approved' => 'bool'])
        ->node('approval', function (NodeContext $context) use (&$effects): NodeResult {
            if ($context->state('approved', false)) {
                $effects++;

                return NodeResult::end();
            }

            return NodeResult::interrupt('approval', ['question' => 'Approve synthetic action?'])
                ->withInterruptPolicy(InterruptPolicy::expiresAfter(1));
        })
        ->edge(StateGraph::START, 'approval'));

    $run = $manager->graph('expired_approval')->thread('audit-expiry')->run();
    expect($manager->expireInterrupts(now()->addMinute()))->toBe(1);
    $interrupts = new DatabaseInterruptStore(app('db'));
    expect($interrupts->find($run->interrupt()['interrupt_id'])['status'])->toBe('expired');
    expect(fn () => $manager->resume($run->runId(), [
        'interrupt_id' => $run->interrupt()['interrupt_id'], 'approved' => true,
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => $manager->{$method}($run->runId(), ['approved' => true]))
        ->toThrow(InvalidArgumentException::class, 'requires interrupt_id');

    expect($effects)->toBe(0);
})->with(['resume', 'resumeStrict', 'resumeContract']);

it('rejects malformed interrupt identities without consuming the pending wait', function (mixed $interruptId) {
    $manager = resumeSafetyManager();
    $manager->define(StateGraph::make('malformed_identity')
        ->node('ask', fn (NodeContext $context): NodeResult => $context->hasResumePayload()
            ? NodeResult::end()
            : NodeResult::interrupt('input'))
        ->edge(StateGraph::START, 'ask'));
    $run = $manager->graph('malformed_identity')->run();

    expect(fn () => $manager->resume($run->runId(), ['interrupt_id' => $interruptId]))
        ->toThrow(InvalidArgumentException::class, 'must be a non-empty string');
    expect($manager->inspect($run->runId())->toRunResult()->interrupt())->toBe($run->interrupt());

    $result = $manager->resume($run->runId(), ['interrupt_id' => $run->interrupt()['interrupt_id']]);
    expect($result->completed())->toBeTrue();
})->with([[null], [''], [123], [[]]]);

it('rejects an interrupt bound to an earlier checkpoint before accepting its answer', function (bool $stateEdit) {
    $manager = resumeSafetyManager();
    $manager->define(StateGraph::make('stale_wait_checkpoint')
        ->state(['approved' => 'bool'])
        ->node('ask', fn (): NodeResult => NodeResult::interrupt($stateEdit ? 'state_edit' : 'approval'))
        ->edge(StateGraph::START, 'ask'));
    $run = $manager->graph('stale_wait_checkpoint')->run();
    $snapshot = $manager->inspect($run->runId())->checkpoint();
    $checkpoint = (new DatabaseCheckpointStore(app('db')))->create(array_merge($snapshot, [
        'step' => $snapshot['step'] + 1,
    ]));

    expect(fn () => $stateEdit
        ? $manager->resumeWithStateEdit($run->runId(), $run->interrupt()['interrupt_id'], ['approved' => true])
        : $manager->resume($run->runId(), ['interrupt_id' => $run->interrupt()['interrupt_id'], 'approved' => true]))
        ->toThrow(InvalidArgumentException::class, 'reconciliation is required');
    expect($manager->inspect($run->runId())->checkpoint())->toBe($checkpoint)
        ->and($manager->inspect($run->runId())->toRunResult()->interrupt())->toBe($run->interrupt())
        ->and($manager->inspect($run->runId())->status())->toBe('interrupted');
})->with([false, true]);

it('rejects a state patch without an interrupt while allowing empty checkpoint recovery', function () {
    $manager = resumeSafetyManager();
    $manager->define(StateGraph::make('unbound_patch')
        ->state(['answer' => 'string'])
        ->node('action', fn (): NodeResult => NodeResult::end(['answer' => 'committed']))
        ->edge(StateGraph::START, 'action'));
    app('events')->listen(GraphCheckpointCreated::class, function (): void {
        throw new RuntimeException('Synthetic disconnect after checkpoint.');
    });
    expect(fn () => $manager->graph('unbound_patch')->thread('unbound-patch')->run())
        ->toThrow(RuntimeException::class, 'Synthetic disconnect');
    $runId = $manager->latestForThreadGraph('unbound-patch', 'unbound_patch')['public_id'];

    expect(fn () => $manager->resume($runId, ['answer' => 'replacement']))
        ->toThrow(InvalidArgumentException::class, 'has no pending interrupt');
    $result = $manager->resume($runId, []);
    expect($result->completed())->toBeTrue()->and($result->state('answer'))->toBe('committed');
});

it('does not rerun committed terminal work after a checkpoint observer disconnect', function (string $continuation) {
    $manager = resumeSafetyManager();
    $effects = 0;
    $manager->define(StateGraph::make('terminal_boundary')
        ->state(['receipt' => 'string'])
        ->node('action', function () use (&$effects): NodeResult {
            $effects++;

            return NodeResult::end(['receipt' => 'already-committed']);
        })
        ->edge(StateGraph::START, 'action'));
    $disconnect = true;
    app('events')->listen(GraphCheckpointCreated::class, function () use (&$disconnect): void {
        if ($disconnect) {
            $disconnect = false;
            throw new RuntimeException('Synthetic observer disconnect after durable checkpoint.');
        }
    });

    expect(fn () => $manager->graph('terminal_boundary')->thread('audit-terminal')->run())
        ->toThrow(RuntimeException::class, 'Synthetic observer disconnect');
    $runId = $manager->latestForThreadGraph('audit-terminal', 'terminal_boundary')['public_id'];
    $snapshot = $manager->inspect($runId);
    expect($snapshot->status())->toBe('running')
        ->and($snapshot->checkpoint()['next_nodes'])->toBe([])
        ->and($snapshot->state('receipt'))->toBe('already-committed')
        ->and($effects)->toBe(1);

    $result = $continuation === 'recover'
        ? $manager->recover($runId)
        : $manager->resume($runId, []);

    expect($result->completed())->toBeTrue()
        ->and($effects)->toBe(1);
})->with(['resume', 'recover']);

it('preserves local Send input and metadata across ordinary and state-edit waits', function (string $mode, bool $stateEdit) {
    config(['agent-graph.execution.mode' => $mode]);
    Queue::fake();
    $manager = resumeSafetyManager();
    $seen = [];
    $send = ['node' => 'ask', 'input' => ['item' => 'order-42'], 'meta' => ['origin' => 'local']];
    $manager->define(StateGraph::make('send_wait_modes')
        ->state(['item' => 'string', 'approved' => 'bool'])
        ->node('route', fn (): NodeResult => NodeResult::sendMany([$send]))
        ->node('ask', function (NodeContext $context) use (&$seen, $stateEdit): NodeResult {
            $seen[] = $context->state('item');

            return $context->hasResumePayload()
                ? NodeResult::end()
                : NodeResult::interrupt($stateEdit ? 'state_edit' : 'approval');
        })
        ->edge(StateGraph::START, 'route'));
    $run = $manager->graph('send_wait_modes')->input(['item' => 'shared'])->run();
    $run = resumeSafetyDrain($manager, $run);
    expect($run->status())->toBe('interrupted')
        ->and($run->state('item'))->toBe('shared')
        ->and(data_get($manager->inspect($run->runId())->checkpoint(), 'meta.runtime.schedule.next'))->toBe([$send]);

    $run = $stateEdit
        ? $manager->resumeWithStateEdit($run->runId(), $run->interrupt()['interrupt_id'], ['approved' => true])
        : $manager->resume($run->runId(), ['interrupt_id' => $run->interrupt()['interrupt_id'], 'approved' => true]);
    $run = resumeSafetyDrain($manager, $run);
    expect($run->completed())->toBeTrue()
        ->and($run->state('item'))->toBe('shared')
        ->and($seen)->toBe(['order-42', 'order-42']);
})->with(['sync', 'queued_supersteps'])->with([false, true]);

it('rejects an elapsed interrupt deadline before expiry maintenance without blocking cancellation', function (bool $stateEdit) {
    $manager = resumeSafetyManager();
    $manager->define(StateGraph::make('elapsed_deadline')
        ->state(['approved' => 'bool'])
        ->node('ask', fn (): NodeResult => NodeResult::interrupt($stateEdit ? 'state_edit' : 'approval')
            ->withInterruptPolicy(InterruptPolicy::expiresAfter(1)))
        ->edge(StateGraph::START, 'ask'));
    $run = $manager->graph('elapsed_deadline')->run();
    $this->travel(2)->seconds();
    $snapshot = $manager->inspect($run->runId(), withHistory: true);

    expect(fn () => $stateEdit
        ? $manager->resumeWithStateEdit($run->runId(), $run->interrupt()['interrupt_id'], ['approved' => true])
        : $manager->resume($run->runId(), ['interrupt_id' => $run->interrupt()['interrupt_id'], 'approved' => true]))
        ->toThrow(InvalidArgumentException::class, 'has expired');
    expect($manager->inspect($run->runId())->status())->toBe('interrupted')
        ->and($manager->inspect($run->runId(), withHistory: true)->checkpoints())->toBe($snapshot->checkpoints())
        ->and($manager->cancel($run->runId())->status())->toBe('cancelled');
})->with([false, true]);

function resumeSafetyDrain(AgentGraphManager $manager, RunResult $run): RunResult
{
    for ($attempt = 0; $attempt < 10 && $run->status() === 'running'; $attempt++) {
        foreach ($manager->nodeExecutions($run->runId()) as $execution) {
            if ($execution['status'] !== 'pending') {
                continue;
            }

            $manager->executeQueuedNode($execution['execution_id']);
            $manager->continueQueuedSuperstep($run->runId(), $execution['step']);
        }

        $run = $manager->inspect($run->runId())->toRunResult();
    }

    return $run;
}

it('preserves Send input when the target node interrupts and resumes', function () {
    $manager = resumeSafetyManager();
    $seen = [];
    $manager->define(StateGraph::make('send_wait_context')
        ->state(['approved' => 'bool', 'item' => 'string'])
        ->node('route', fn (): NodeResult => NodeResult::send('ask', ['item' => 'order-42']))
        ->node('ask', function (NodeContext $context) use (&$seen): NodeResult {
            $seen[] = $context->state('item');

            return $context->hasResumePayload()
                ? NodeResult::end()
                : NodeResult::interrupt('approval', ['question' => 'Approve '.$context->state('item').'?']);
        })
        ->edge(StateGraph::START, 'route'));

    $run = $manager->graph('send_wait_context')->run();
    expect($run->status())->toBe('interrupted')
        ->and($run->state())->not->toHaveKey('item');
    $result = $manager->resume($run->runId(), [
        'interrupt_id' => $run->interrupt()['interrupt_id'], 'approved' => true,
    ]);

    expect($result->completed())->toBeTrue()
        ->and($seen)->toBe(['order-42', 'order-42']);
});

it('preserves every Send branch and its input when resuming a running checkpoint', function (string $continuation) {
    $manager = resumeSafetyManager();
    $seen = [];
    $manager->define(StateGraph::make('send_resume_context')
        ->state(['item' => 'string'])
        ->node('route', fn (): NodeResult => NodeResult::sendMany([
            ['node' => 'worker', 'input' => ['item' => 'A']],
            ['node' => 'worker', 'input' => ['item' => 'B']],
        ]))
        ->node('worker', function (NodeContext $context) use (&$seen): NodeResult {
            $seen[] = $context->state('item');

            return NodeResult::end();
        })
        ->edge(StateGraph::START, 'route'));
    $disconnect = true;
    app('events')->listen(GraphCheckpointCreated::class, function () use (&$disconnect): void {
        if ($disconnect) {
            $disconnect = false;
            throw new RuntimeException('Synthetic disconnect before the fan-out.');
        }
    });
    expect(fn () => $manager->graph('send_resume_context')->thread('audit-send')->run())
        ->toThrow(RuntimeException::class, 'Synthetic disconnect');
    $runId = $manager->latestForThreadGraph('audit-send', 'send_resume_context')['public_id'];
    expect(data_get($manager->inspect($runId)->checkpoint(), 'meta.runtime.schedule.next.1.input.item'))->toBe('B');

    $result = $continuation === 'recover'
        ? $manager->recover($runId)
        : $manager->resume($runId, []);

    expect($result->completed())->toBeTrue()
        ->and($seen)->toBe(['A', 'B']);
})->with(['resume', 'recover']);

it('preserves an accepted resume answer when a later caller omits interrupt_id', function (string $continuation) {
    $manager = resumeSafetyManager();
    $manager->define(StateGraph::make('accepted_answer')
        ->state(['answer' => 'string'])
        ->node('ask', fn (NodeContext $context): NodeResult => $context->hasResumePayload()
            ? NodeResult::end(['answer' => $context->resumePayload()['answer']])
            : NodeResult::interrupt('input', ['question' => 'Your synthetic answer?']))
        ->edge(StateGraph::START, 'ask'));
    $run = $manager->graph('accepted_answer')->run();
    $disconnect = true;
    app('events')->listen(GraphResumed::class, function () use (&$disconnect): void {
        if ($disconnect) {
            $disconnect = false;
            throw new RuntimeException('Synthetic disconnect after accepting resume.');
        }
    });

    expect(fn () => $manager->resume($run->runId(), [
        'interrupt_id' => $run->interrupt()['interrupt_id'], 'answer' => 'accepted',
    ]))->toThrow(RuntimeException::class, 'Synthetic disconnect after accepting resume.');
    expect(data_get($manager->inspect($run->runId())->meta(), 'runtime.recovery.pending_resume.resume_payload.answer'))
        ->toBe('accepted');

    // The existing ID-aware path correctly rejects the changed answer.
    expect(fn () => $manager->resume($run->runId(), [
        'interrupt_id' => $run->interrupt()['interrupt_id'], 'answer' => 'replacement',
    ]))->toThrow(InvalidArgumentException::class);

    if ($continuation === 'resume') {
        expect(fn () => $manager->resume($run->runId(), ['answer' => 'replacement']))
            ->toThrow(InvalidArgumentException::class, 'requires interrupt_id');
    }

    $result = $manager->recover($run->runId());
    expect($result->completed())->toBeTrue()
        ->and($result->state('answer'))->toBe('accepted');
})->with(['resume', 'recover']);
