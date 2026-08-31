<?php

use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Contracts\CheckpointStore;
use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Contracts\MemoryStore;
use Heiner\AgentGraph\Contracts\NodeExecutionStore;
use Heiner\AgentGraph\Contracts\RunStore;
use Heiner\AgentGraph\Contracts\TaskStore;
use Heiner\AgentGraph\Contracts\TraceStore;
use Heiner\AgentGraph\Contracts\WriteStore;
use Heiner\AgentGraph\Graph\GraphDefinition;
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
use Heiner\AgentGraph\Runtime\RunEventDispatcher;
use Heiner\AgentGraph\Runtime\RunResult;
use Heiner\AgentGraph\Runtime\RuntimeOptions;
use Heiner\AgentGraph\Runtime\SubgraphNode;
use Illuminate\Support\Facades\Queue;

it('registers embedded definitions while preserving explicit registration of the same child', function () {
    $manager = app(AgentGraphManager::class);
    $child = subgraphHardeningInputGraph('registered_child');
    $manager->define($child);
    $node = SubgraphNode::make('child', $child);
    $parent = StateGraph::make('registered_parent')
        ->node('child', $node)
        ->node('second', SubgraphNode::make('second', $child))
        ->edge(StateGraph::START, 'child')
        ->compile();

    $manager->define($parent);

    expect($manager->definition('registered_child'))->toBe($child)
        ->and($manager->definition('registered_parent'))->toBe($parent)
        ->and($node->graphDefinition())->toBe($child)
        ->and(SubgraphNode::make('named', 'registered_child')->graphDefinition())->toBeNull();
});

it('rejects conflicting embedded keys without partially changing the graph registry', function () {
    $manager = app(AgentGraphManager::class);
    $existing = $manager->define(subgraphHardeningInputGraph('existing'));
    $parent = StateGraph::make('conflicting')
        ->node('child', SubgraphNode::make('child', subgraphHardeningInputGraph('conflicting')))
        ->edge(StateGraph::START, 'child');

    expect(fn () => $manager->define($parent))->toThrow(InvalidArgumentException::class, 'conflicting definitions')
        ->and($manager->definitions())->toBe(['existing' => $existing]);
});

it('visits cyclic embedded definition references only once while registering graphs', function () {
    $manager = app(AgentGraphManager::class);
    $node = SubgraphHardeningMutableNode::make('child', 'cyclic_parent');
    $parent = StateGraph::make('cyclic_parent')
        ->node('child', $node)
        ->edge(StateGraph::START, 'child')
        ->compile();
    $node->pointTo($parent);

    $manager->define($parent);

    expect($manager->definitions())->toBe(['cyclic_parent' => $parent]);
});

it('resumes nested inline subgraphs after reconstructing only the parent in a fresh manager', function () {
    $manager = app(AgentGraphManager::class);
    $leaf = subgraphHardeningInputGraph('inline_leaf');
    $middle = subgraphHardeningParentGraph('inline_middle', $leaf);
    $parent = subgraphHardeningParentGraph('inline_root', $middle);
    $manager->define($parent);
    $waiting = $manager->graph('inline_root')->run();

    $manager = subgraphHardeningFreshManager();
    $manager->define($parent);
    $completed = $manager->resume($waiting->runId(), subgraphHardeningResumePayload($waiting, ['answer' => 'nested answer']));

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('answer'))->toBe('nested answer')
        ->and($manager->runs())->toHaveCount(3)
        ->and(array_column($manager->runs(), 'status'))->toBe(['completed', 'completed', 'completed']);
});

it('keeps a delayed child pending and consumes its completed result after a fresh worker resumes it', function () {
    Queue::fake();
    $manager = app(AgentGraphManager::class);
    $after = 0;
    $child = StateGraph::make('inline_delay_child')
        ->state(['answer' => 'string'])
        ->node('wait', fn (NodeContext $context): NodeResult => $context->hasResumePayload()
            ? NodeResult::end(['answer' => 'ready'])
            : NodeResult::interrupt('delay', ['resume_at' => now()->addMinute()->toISOString()]))
        ->edge(StateGraph::START, 'wait')
        ->compile();
    $parent = StateGraph::make('inline_delay_parent')
        ->state(['answer' => 'string'])
        ->node('child', SubgraphNode::make('child', $child)->mapped())
        ->node('after', function () use (&$after): NodeResult {
            $after++;

            return NodeResult::end();
        })
        ->edge(StateGraph::START, 'child')
        ->edge('child', 'after')
        ->compile();
    $manager->define($parent);
    $waiting = $manager->graph('inline_delay_parent')->run();

    expect($waiting->status())->toBe('interrupted')
        ->and($waiting->interrupt()['payload']['child_status'])->toBe('delayed')
        ->and($after)->toBe(0);

    $manager = subgraphHardeningFreshManager();
    $manager->define($parent);
    $this->travel(2)->minutes();
    $binding = $waiting->interrupt()['payload'];
    $manager->resume($binding['child_run_id'], ['interrupt_id' => $binding['child_interrupt_id']]);
    $completed = $manager->resume($waiting->runId(), subgraphHardeningResumePayload($waiting));

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('answer'))->toBe('ready')
        ->and($after)->toBe(1)
        ->and($manager->childRuns($waiting->runId()))->toHaveCount(1);
});

it('does not map a running queued child as successful output', function () {
    Queue::fake();
    $manager = app(AgentGraphManager::class);
    $mapped = false;
    $child = $manager->define(subgraphHardeningInputGraph('queued_child'));
    $subgraph = SubgraphNode::make('child', $child)->mapped(output: function () use (&$mapped): array {
        $mapped = true;

        return [];
    });
    $manager->define(StateGraph::make('queued_child_parent')
        ->node('child', function (NodeContext $context) use ($subgraph): NodeResult {
            config(['agent-graph.execution.mode' => 'queued_supersteps']);

            try {
                return $subgraph($context);
            } finally {
                config(['agent-graph.execution.mode' => 'sync']);
            }
        })
        ->edge(StateGraph::START, 'child'));

    $result = $manager->graph('queued_child_parent')->run();

    expect($result->failed())->toBeTrue()
        ->and($result->error()['meta']['child_status'])->toBe('running')
        ->and($mapped)->toBeFalse();
});

it('propagates a bound child cancellation without mapping its partial state', function () {
    $manager = app(AgentGraphManager::class);
    $mapped = false;
    $child = subgraphHardeningInputGraph('cancelled_child');
    $parent = StateGraph::make('cancelled_child_parent')
        ->node('child', SubgraphNode::make('child', $child)->mapped(output: function () use (&$mapped): array {
            $mapped = true;

            return [];
        }))
        ->edge(StateGraph::START, 'child');
    $manager->define($parent);
    $waiting = $manager->graph('cancelled_child_parent')->run();
    $manager->cancel($waiting->interrupt()['payload']['child_run_id']);

    $result = $manager->resume($waiting->runId(), subgraphHardeningResumePayload($waiting));

    expect($result->failed())->toBeTrue()
        ->and($result->error()['meta']['child_status'])->toBe('cancelled')
        ->and($mapped)->toBeFalse();
});

it('rejects a foreign child before accepting the parent response', function () {
    $manager = app(AgentGraphManager::class);
    $manager->define(subgraphHardeningParentGraph('binding_parent', subgraphHardeningInputGraph('binding_child')));
    $first = $manager->graph('binding_parent')->run();
    $second = $manager->graph('binding_parent')->run();
    $firstBefore = $manager->inspect($first->runId());
    $foreign = $second->interrupt()['payload'];

    expect(fn () => $manager->resume($first->runId(), [
        'interrupt_id' => $first->interrupt()['interrupt_id'],
        'child_run_id' => $foreign['child_run_id'],
        'child_interrupt_id' => $foreign['child_interrupt_id'],
        'answer' => 'foreign answer',
    ]))->toThrow(InvalidArgumentException::class);

    expect($manager->inspect($first->runId())->run())->toBe($firstBefore->run())
        ->and($manager->inspect($first->runId())->interrupt())->toBe($firstBefore->interrupt())
        ->and(array_unique(array_column($manager->runs(), 'status')))->toBe(['interrupted']);
});

it('does not use an old parent response to approve a newer child interrupt', function () {
    $manager = app(AgentGraphManager::class);
    $child = StateGraph::make('new_child_wait')
        ->node('ask', fn (): NodeResult => NodeResult::interrupt('input', ['prompt' => 'Another answer']))
        ->edge(StateGraph::START, 'ask')
        ->compile();
    $manager->define(subgraphHardeningParentGraph('old_parent_wait', $child));
    $waiting = $manager->graph('old_parent_wait')->run();
    $binding = $waiting->interrupt()['payload'];
    $newChildWait = $manager->resume($binding['child_run_id'], ['interrupt_id' => $binding['child_interrupt_id']]);

    expect($newChildWait->interrupt()['interrupt_id'])->not->toBe($binding['child_interrupt_id']);
    expect(fn () => $manager->resume($waiting->runId(), subgraphHardeningResumePayload($waiting)))
        ->toThrow(InvalidArgumentException::class);

    expect($manager->inspect($waiting->runId())->status())->toBe('interrupted')
        ->and($manager->inspect($waiting->runId())->interrupt()['interrupt_id'])->toBe($waiting->interrupt()['interrupt_id'])
        ->and($manager->inspect($binding['child_run_id'])->interrupt()['interrupt_id'])->toBe($newChildWait->interrupt()['interrupt_id']);
});

it('retries an already accepted parent resume without requiring an unresolved parent interrupt', function () {
    app()->singleton(GraphRuntime::class, SubgraphHardeningCrashBeforeContinuationRuntime::class);
    $manager = app(AgentGraphManager::class);
    $manager->define(subgraphHardeningParentGraph('recover_parent', subgraphHardeningInputGraph('recover_child')));
    $waiting = $manager->graph('recover_parent')->run();
    $payload = subgraphHardeningResumePayload($waiting, ['answer' => 'accepted answer']);
    app(GraphRuntime::class)->crashGraphKey = 'recover_parent';

    expect(fn () => $manager->resume($waiting->runId(), $payload))
        ->toThrow(RuntimeException::class, 'Injected subgraph continuation crash.');

    expect($manager->inspect($waiting->runId())->status())->toBe('running')
        ->and($manager->inspect($waiting->runId())->interrupt())->toBeNull();

    $completed = $manager->resume($waiting->runId(), $payload);

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('answer'))->toBe('accepted answer')
        ->and($manager->childRuns($waiting->runId()))->toHaveCount(1);
});

it('retains nested child identity while recovering its already accepted response', function () {
    app()->singleton(GraphRuntime::class, SubgraphHardeningCrashBeforeContinuationRuntime::class);
    $manager = app(AgentGraphManager::class);
    $middle = subgraphHardeningParentGraph('recover_middle', subgraphHardeningInputGraph('recover_leaf'));
    $manager->define(subgraphHardeningParentGraph('recover_root', $middle));
    $waiting = $manager->graph('recover_root')->run();
    $middleId = $waiting->interrupt()['payload']['child_run_id'];
    $middleWait = $manager->inspect($middleId)->toRunResult();
    app(GraphRuntime::class)->crashGraphKey = 'recover_middle';

    expect(fn () => $manager->resume($middleId, subgraphHardeningResumePayload($middleWait, ['answer' => 'recovered nested answer'])))
        ->toThrow(RuntimeException::class, 'Injected subgraph continuation crash.');

    $completed = $manager->resume($waiting->runId(), subgraphHardeningResumePayload($waiting, ['answer' => 'recovered nested answer']));

    expect($completed->error())->toBeNull()
        ->and($completed->completed())->toBeTrue()
        ->and($completed->state('answer'))->toBe('recovered nested answer')
        ->and($manager->runs())->toHaveCount(3);
});

it('resumes a persisted v0.15.1 parent and child without new interrupt metadata', function (string $store) {
    if ($store === 'database') {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->artisan('migrate')->run();

        foreach ([
            RunStore::class => DatabaseRunStore::class,
            CheckpointStore::class => DatabaseCheckpointStore::class,
            InterruptStore::class => DatabaseInterruptStore::class,
            MemoryStore::class => DatabaseMemoryStore::class,
            NodeExecutionStore::class => DatabaseNodeExecutionStore::class,
            TaskStore::class => DatabaseTaskStore::class,
            TraceStore::class => DatabaseTraceStore::class,
            WriteStore::class => DatabaseWriteStore::class,
        ] as $contract => $implementation) {
            app()->singleton($contract, $implementation);
        }
    }

    $manager = app(AgentGraphManager::class);
    $childGraph = subgraphHardeningInputGraph('legacy_child');
    $manager->define(subgraphHardeningParentGraph('legacy_parent', $childGraph));
    $runs = app(RunStore::class);
    $checkpoints = app(CheckpointStore::class);
    $interrupts = app(InterruptStore::class);
    $parent = $runs->create('legacy_parent', '1', 'legacy-thread');
    $child = $runs->create('legacy_child', '1', 'legacy-child-thread', [], [
        'parent' => ['run_id' => $parent['public_id'], 'checkpoint_id' => null, 'node_id' => 'child', 'depth' => 1, 'relationship' => 'subgraph'],
    ]);

    // The 0.15.1 checkpoint shape has no durable interrupt intent or recovery marker.
    $childCheckpoint = $checkpoints->create([
        'run_id' => $child['public_id'], 'thread_id' => $child['thread_id'],
        'graph_key' => 'legacy_child', 'graph_version' => '1', 'step' => 1,
        'state' => [], 'next_nodes' => ['ask'], 'completed_nodes' => ['ask'], 'interrupts' => [], 'meta' => [],
    ]);
    $childInterrupt = $interrupts->create([
        'run_id' => $child['public_id'], 'checkpoint_id' => $childCheckpoint['checkpoint_id'],
        'node_id' => 'ask', 'type' => 'input', 'payload' => ['prompt' => 'Your answer'],
    ]);
    $runs->update($child['public_id'], ['status' => 'interrupted', 'current_checkpoint_id' => $childCheckpoint['checkpoint_id']]);
    $parentCheckpoint = $checkpoints->create([
        'run_id' => $parent['public_id'], 'thread_id' => $parent['thread_id'],
        'graph_key' => 'legacy_parent', 'graph_version' => '1', 'step' => 1,
        'state' => [], 'next_nodes' => ['child'], 'completed_nodes' => ['child'], 'interrupts' => [], 'meta' => [],
    ]);
    $parentInterrupt = $interrupts->create([
        'run_id' => $parent['public_id'], 'checkpoint_id' => $parentCheckpoint['checkpoint_id'],
        'node_id' => 'child', 'type' => 'subgraph', 'payload' => [
            'child_run_id' => $child['public_id'], 'child_interrupt_id' => $childInterrupt['interrupt_id'],
            'child_status' => 'interrupted', 'child_interrupt' => $childInterrupt,
        ],
    ]);
    $runs->update($parent['public_id'], ['status' => 'interrupted', 'current_checkpoint_id' => $parentCheckpoint['checkpoint_id']]);

    $completed = $manager->resume($parent['public_id'], [
        'interrupt_id' => $parentInterrupt['interrupt_id'],
        'child_run_id' => $child['public_id'], 'child_interrupt_id' => $childInterrupt['interrupt_id'],
        'answer' => 'legacy answer',
    ]);

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('answer'))->toBe('legacy answer');
})->with(['memory', 'database']);

function subgraphHardeningInputGraph(string $key): GraphDefinition
{
    return StateGraph::make($key)
        ->state(['answer' => 'string'])
        ->node('ask', fn (NodeContext $context): NodeResult => $context->hasResumePayload()
            ? NodeResult::end(['answer' => (string) $context->state('answer')])
            : NodeResult::interrupt('input', ['prompt' => 'Your answer']))
        ->edge(StateGraph::START, 'ask')
        ->compile();
}

function subgraphHardeningParentGraph(string $key, GraphDefinition $child): GraphDefinition
{
    return StateGraph::make($key)
        ->state(['answer' => 'string'])
        ->node('child', SubgraphNode::make('child', $child)->mapped(output: fn (array $state): array => ['answer' => $state['answer'] ?? '']))
        ->edge(StateGraph::START, 'child')
        ->compile();
}

function subgraphHardeningFreshManager(): AgentGraphManager
{
    $manager = new AgentGraphManager(app(GraphRuntime::class), app(RunEventDispatcher::class));
    app()->instance(AgentGraphManager::class, $manager);

    return $manager;
}

function subgraphHardeningResumePayload(RunResult $waiting, array $answer = []): array
{
    $interrupt = $waiting->interrupt();

    return [
        'interrupt_id' => $interrupt['interrupt_id'],
        'child_run_id' => $interrupt['payload']['child_run_id'],
        'child_interrupt_id' => $interrupt['payload']['child_interrupt_id'],
        ...$answer,
    ];
}

final class SubgraphHardeningCrashBeforeContinuationRuntime extends GraphRuntime
{
    public ?string $crashGraphKey = null;

    protected function continueLocked(GraphDefinition $graph, array $run, array $state, array $nextNodes, array $resumeContext = [], ?RuntimeOptions $options = null): RunResult
    {
        if ($graph->key() === $this->crashGraphKey && array_key_exists('resume_payload', $resumeContext)) {
            $this->crashGraphKey = null;

            throw new RuntimeException('Injected subgraph continuation crash.');
        }

        return parent::continueLocked($graph, $run, $state, $nextNodes, $resumeContext, $options);
    }
}

final class SubgraphHardeningMutableNode extends SubgraphNode
{
    public static function make(string $id, string|GraphDefinition $graph): self
    {
        return new self($id, $graph);
    }

    public function pointTo(GraphDefinition $graph): void
    {
        $this->graph = $graph;
    }
}
