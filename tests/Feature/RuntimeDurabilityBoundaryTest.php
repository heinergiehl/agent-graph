<?php

use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Events\GraphCheckpointCreated;
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
use Heiner\AgentGraph\Queue\ContinueSuperstepJob;
use Heiner\AgentGraph\Queue\NodeExecutionJob;
use Heiner\AgentGraph\Runtime\GraphRuntime;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunEventDispatcher;
use Heiner\AgentGraph\Runtime\RunResult;
use Heiner\AgentGraph\Runtime\RuntimeOptions;
use Heiner\AgentGraph\Runtime\Send;
use Heiner\AgentGraph\Runtime\SubgraphNode;
use Heiner\AgentGraph\Support\DelaySchedulerResolver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
    Queue::fake();
});

it('commits the approval and wait status before notifying checkpoint observers', function (string $mode) {
    config(['agent-graph.execution.mode' => $mode]);
    [$manager, , $runs, $checkpoints, $interrupts, $executions] = durabilityBoundaryManager();
    $effects = 0;
    durabilityBoundaryApprovalGraph($manager, $effects);

    Event::listen(GraphCheckpointCreated::class, function () {
        throw new RuntimeException('Injected failure after checkpoint commit.');
    });

    $invoke = function () use ($mode, $manager, $executions) {
        $result = $manager->graph('approval_boundary')->thread('approval-boundary')->run();

        if ($mode === 'queued_supersteps') {
            $execution = $executions->listForRun($result->runId())[0];
            $manager->executeQueuedNode($execution['execution_id']);
            $manager->continueQueuedSuperstep($result->runId(), 1);
        }
    };

    expect($invoke)->toThrow(RuntimeException::class, 'Injected failure after checkpoint commit.');
    Event::forget(GraphCheckpointCreated::class);
    $run = $runs->list(['graph_key' => 'approval_boundary'])[0];
    $runId = $run['public_id'];
    $checkpoint = $checkpoints->latestForRun($runId);
    $interrupt = $interrupts->pendingForRun($runId);

    expect($run['status'])->toBe('interrupted')
        ->and($checkpoint['next_nodes'])->toBe(['approval'])
        ->and($interrupt['checkpoint_id'])->toBe($checkpoint['checkpoint_id'])
        ->and($effects)->toBe(0);

    $recovered = $manager->recover($runId);

    expect($recovered->status())->toBe('interrupted')
        ->and($recovered->interrupt()['interrupt_id'])->toBe($interrupt['interrupt_id'])
        ->and($effects)->toBe(0);

    if ($mode === 'queued_supersteps') {
        expect($manager->continueQueuedSuperstep($runId, 1)->interrupt()['interrupt_id'])
            ->toBe($interrupt['interrupt_id']);
    }

    $resumed = $manager->resume($runId, ['interrupt_id' => $interrupt['interrupt_id'], 'approved' => true]);

    if ($mode === 'queued_supersteps') {
        foreach ([2, 3] as $step) {
            foreach ($executions->listForRunStep($runId, $step) as $execution) {
                $manager->executeQueuedNode($execution['execution_id']);
            }

            $resumed = $manager->continueQueuedSuperstep($runId, $step);
        }
    }

    expect($resumed->status())->toBe('completed')
        ->and($effects)->toBe(1);
})->with(['sync', 'queued_supersteps']);

it('does not leave an orphan checkpoint when persisting the wait status fails', function (string $mode) {
    config(['agent-graph.execution.mode' => $mode]);
    $runs = new DurabilityBoundaryFailWaitingRunStore(app('db'));
    [$manager, , , $checkpoints, $interrupts, $executions] = durabilityBoundaryManager(runs: $runs);
    $effects = 0;
    durabilityBoundaryApprovalGraph($manager, $effects);

    $result = $manager->graph('approval_boundary')->thread('atomic-wait')->run();

    if ($mode === 'queued_supersteps') {
        $execution = $executions->listForRun($result->runId())[0];
        $manager->executeQueuedNode($execution['execution_id']);
        $result = $manager->continueQueuedSuperstep($result->runId(), 1);
    }

    expect($result->status())->toBe('failed')
        ->and($checkpoints->latestForRun($result->runId()))->toBeNull()
        ->and($interrupts->pendingForRun($result->runId()))->toBeNull()
        ->and($effects)->toBe(0);
})->with(['sync', 'queued_supersteps']);

it('refuses an inconsistent legacy recovery frontier without executing a successor', function () {
    [$manager, , $runs, $checkpoints] = durabilityBoundaryManager();
    $effects = 0;
    durabilityBoundaryApprovalGraph($manager, $effects);
    $run = $runs->create('approval_boundary', '1', 'legacy-incomplete-wait');
    $checkpoints->create([
        'run_id' => $run['public_id'],
        'thread_id' => $run['thread_id'],
        'graph_key' => 'approval_boundary',
        'graph_version' => '1',
        'step' => 1,
        'state' => [],
        'next_nodes' => ['approval'],
        'completed_nodes' => ['approval'],
        'interrupts' => [],
        'meta' => ['runtime' => ['schedule' => ['next' => [['node' => 'effect', 'input' => [], 'meta' => []]]]]],
    ]);

    expect(fn () => $manager->recover($run['public_id']))
        ->toThrow(RuntimeException::class, 'inconsistent recovery schedule');
    expect($effects)->toBe(0)
        ->and($runs->find($run['public_id'])['status'])->toBe('running');
});

it('redrives a committed first queued frontier after dispatch is lost', function () {
    config(['agent-graph.execution.mode' => 'queued_supersteps']);
    [$manager, $runtime, $runs, $checkpoints, , $executions] = durabilityBoundaryManager(DurabilityBoundaryDispatchRuntime::class);
    $effects = 0;
    $manager->define(StateGraph::make('initial_dispatch')
        ->state(['done' => 'bool'])
        ->node('work', function () use (&$effects) {
            $effects++;

            return NodeResult::end(['done' => true]);
        })->edge(StateGraph::START, 'work'));
    $runtime->failNodeDispatch = true;

    expect(fn () => $manager->graph('initial_dispatch')->thread('queued-initial')->run())
        ->toThrow(RuntimeException::class, 'Injected node dispatch outage.');
    $run = $runs->list(['graph_key' => 'initial_dispatch'])[0];

    expect($checkpoints->latestForRun($run['public_id']))->toBeNull()
        ->and($executions->listForRun($run['public_id']))->toHaveCount(1);
    $runtime->failNodeDispatch = false;
    $manager->recover($run['public_id']);
    Queue::assertPushed(NodeExecutionJob::class, 1);

    $execution = $executions->listForRun($run['public_id'])[0];
    $manager->executeQueuedNode($execution['execution_id']);
    $completed = $manager->continueQueuedSuperstep($run['public_id'], 1);

    expect($completed->status())->toBe('completed')
        ->and($completed->state('done'))->toBeTrue()
        ->and($effects)->toBe(1);
});

it('does not rerun arbitrary input when no checkpoint or queued frontier exists', function () {
    config(['agent-graph.execution.mode' => 'queued_supersteps']);
    [$manager, , $runs] = durabilityBoundaryManager();
    $effects = 0;
    durabilityBoundaryApprovalGraph($manager, $effects);
    $run = $runs->create('approval_boundary', '1', 'no-durable-frontier');

    expect(fn () => $manager->recover($run['public_id']))
        ->toThrow(RuntimeException::class, 'has no checkpoint');
    expect($effects)->toBe(0);
});

it('keeps a successful queued result durable when continuation dispatch fails', function () {
    config(['agent-graph.execution.mode' => 'queued_supersteps']);
    [$manager, $runtime, $runs, , , $executions] = durabilityBoundaryManager(DurabilityBoundaryDispatchRuntime::class);
    $effects = 0;
    $manager->define(StateGraph::make('continuation_dispatch')
        ->state(['receipt' => 'string'])
        ->node('work', function () use (&$effects) {
            $effects++;

            return NodeResult::end(['receipt' => 'stored-success']);
        })->edge(StateGraph::START, 'work'));
    $result = $manager->graph('continuation_dispatch')->thread('queued-finish')->run();
    $execution = $executions->listForRun($result->runId())[0];
    $runtime->failContinuationDispatch = true;

    expect(fn () => $manager->executeQueuedNode($execution['execution_id']))
        ->toThrow(RuntimeException::class, 'Injected continuation dispatch outage.');
    expect($executions->find($execution['execution_id'])['status'])->toBe('completed')
        ->and($executions->find($execution['execution_id'])['writes'])->toBe(['receipt' => 'stored-success'])
        ->and($runs->find($result->runId())['status'])->toBe('running');

    $runtime->failContinuationDispatch = false;
    $manager->executeQueuedNode($execution['execution_id']);
    $completed = $manager->continueQueuedSuperstep($result->runId(), 1);

    expect($completed->status())->toBe('completed')
        ->and($completed->state('receipt'))->toBe('stored-success')
        ->and($effects)->toBe(1);
});

it('preserves cancellation when an in-flight worker later fails', function (bool $throws) {
    config(['agent-graph.execution.mode' => 'queued_supersteps']);
    [$manager, , $runs, , , $executions] = durabilityBoundaryManager();
    $manager->define(StateGraph::make('cancel_running_worker')
        ->node('work', function () use ($throws) {
            Fiber::suspend();

            if ($throws) {
                throw new RuntimeException('Late worker failure.');
            }

            return NodeResult::fail('Late worker failure.');
        })->edge(StateGraph::START, 'work'));
    $result = $manager->graph('cancel_running_worker')->thread('cancel-race')->run();
    $execution = $executions->listForRun($result->runId())[0];
    $worker = new Fiber(fn () => $manager->executeQueuedNode($execution['execution_id']));
    $worker->start();
    $manager->cancel($result->runId());
    $worker->resume();

    expect($runs->find($result->runId())['status'])->toBe('cancelled')
        ->and($runs->find($result->runId())['error'])->toBeNull();
})->with([true, false]);

function durabilityBoundaryManager(string $runtimeClass = GraphRuntime::class, ?DatabaseRunStore $runs = null): array
{
    $db = app('db');
    $runs ??= new DatabaseRunStore($db);
    $checkpoints = new DatabaseCheckpointStore($db);
    $interrupts = new DatabaseInterruptStore($db);
    $executions = new DatabaseNodeExecutionStore($db);
    $events = new RunEventDispatcher;
    $runtime = new $runtimeClass(
        container: app(), runs: $runs, checkpoints: $checkpoints,
        writes: new DatabaseWriteStore($db), tasks: new DatabaseTaskStore($db),
        interrupts: $interrupts, memory: new DatabaseMemoryStore($db),
        traces: new DatabaseTraceStore($db), locks: app(LockProvider::class),
        delaySchedulers: app(DelaySchedulerResolver::class), events: $events,
        nodeExecutions: $executions,
    );

    return [new AgentGraphManager($runtime, $events), $runtime, $runs, $checkpoints, $interrupts, $executions];
}

function durabilityBoundaryApprovalGraph(AgentGraphManager $manager, int &$effects): void
{
    $manager->define(StateGraph::make('approval_boundary')
        ->state(['effect_done' => 'bool', 'approved' => 'bool'])
        ->node('approval', fn (NodeContext $context) => $context->hasResumePayload()
            ? NodeResult::write([])
            : NodeResult::interrupt('approval', ['prompt' => 'Approve this operation?']))
        ->node('effect', function () use (&$effects) {
            $effects++;

            return NodeResult::end(['effect_done' => true]);
        })->edge(StateGraph::START, 'approval')->edge('approval', 'effect'));
}

class DurabilityBoundaryDispatchRuntime extends GraphRuntime
{
    public bool $failNodeDispatch = false;

    public bool $failContinuationDispatch = false;

    protected function dispatchNodeExecution(string $executionId): void
    {
        if ($this->failNodeDispatch) {
            throw new RuntimeException('Injected node dispatch outage.');
        }

        parent::dispatchNodeExecution($executionId);
    }

    protected function dispatchContinueSuperstep(string $runId, int $step): void
    {
        if ($this->failContinuationDispatch) {
            throw new RuntimeException('Injected continuation dispatch outage.');
        }

        parent::dispatchContinueSuperstep($runId, $step);
    }
}

class DurabilityBoundaryFailWaitingRunStore extends DatabaseRunStore
{
    public function update(string $runId, array $attributes): array
    {
        if (in_array($attributes['status'] ?? null, ['interrupted', 'delayed'], true)) {
            throw new RuntimeException('Injected failure while committing wait status.');
        }

        return parent::update($runId, $attributes);
    }
}

it('recovers distinct Send inputs targeting the same node', function (string $mode) {
    config(['agent-graph.execution.mode' => $mode]);
    [$manager, , $runs, $checkpoints, , $executions] = durabilityBoundaryManager();
    $calls = 0;
    $manager->define(StateGraph::make('recover_duplicate_targets')
        ->state(['seen' => 'array'])
        ->reducer('seen', 'append')
        ->node('dispatch', fn () => NodeResult::sendMany([
            Send::to('worker', ['item' => 'A']),
            Send::to('worker', ['item' => 'B']),
        ]))
        ->node('worker', function (NodeContext $context) use (&$calls) {
            $calls++;

            return NodeResult::write(['seen' => [$context->state('item')]]);
        })->edge(StateGraph::START, 'dispatch'));
    Event::listen(GraphCheckpointCreated::class, function () {
        throw new RuntimeException('Injected failure after fan-out checkpoint.');
    });
    $invoke = function () use ($mode, $manager, $executions) {
        $result = $manager->graph('recover_duplicate_targets')->thread('same-target')->run();

        if ($mode === 'queued_supersteps') {
            $execution = $executions->listForRun($result->runId())[0];
            $manager->executeQueuedNode($execution['execution_id']);
            $manager->continueQueuedSuperstep($result->runId(), 1);
        }
    };

    expect($invoke)->toThrow(RuntimeException::class, 'Injected failure after fan-out checkpoint.');
    Event::forget(GraphCheckpointCreated::class);
    $run = $runs->list(['graph_key' => 'recover_duplicate_targets'])[0];
    expect($checkpoints->latestForRun($run['public_id'])['next_nodes'])->toBe(['worker', 'worker']);
    $result = $manager->recover($run['public_id']);

    if ($mode === 'queued_supersteps') {
        foreach ($executions->listForRunStep($run['public_id'], 2) as $execution) {
            $manager->executeQueuedNode($execution['execution_id']);
        }

        $result = $manager->continueQueuedSuperstep($run['public_id'], 2);
    }

    expect($result->status())->toBe('completed')
        ->and($result->state('seen'))->toBe(['A', 'B'])
        ->and($calls)->toBe(2);
})->with(['sync', 'queued_supersteps']);

it('ignores stale queue workers after a replacement worker has committed', function (string $lateOutcome) {
    config(['agent-graph.execution.mode' => 'queued_supersteps', 'agent-graph.execution.node_lease_seconds' => 1]);
    [$manager, , $runs, , , $executions] = durabilityBoundaryManager();
    $calls = 0;
    $manager->define(StateGraph::make('reclaimed_worker')
        ->state(['winner' => 'string'])
        ->node('work', function () use (&$calls, $lateOutcome) {
            $calls++;

            if ($calls === 1) {
                Fiber::suspend();

                return match ($lateOutcome) {
                    'throw' => throw new RuntimeException('Stale worker exception.'),
                    'fail' => NodeResult::fail('Stale worker failure.'),
                    'interrupt' => NodeResult::interrupt('approval'),
                    default => NodeResult::end(['winner' => 'old']),
                };
            }

            return NodeResult::end(['winner' => 'new']);
        })->edge(StateGraph::START, 'work'));
    $run = $manager->graph('reclaimed_worker')->thread('lease-race')->run();
    $execution = $executions->listForRun($run->runId())[0];
    $oldWorker = new Fiber(fn () => $manager->executeQueuedNode($execution['execution_id']));
    $oldWorker->start();
    $this->travel(2)->seconds();
    $manager->executeQueuedNode($execution['execution_id']);
    $oldWorker->resume();
    $this->travelBack();

    expect($executions->find($execution['execution_id'])['status'])->toBe('completed')
        ->and($executions->find($execution['execution_id'])['writes'])->toBe(['winner' => 'new'])
        ->and($runs->find($run->runId())['status'])->toBe('running');
    $result = $manager->continueQueuedSuperstep($run->runId(), 1);

    expect($result->status())->toBe('completed')
        ->and($result->state('winner'))->toBe('new')
        ->and($calls)->toBe(2);
})->with(['throw', 'fail', 'interrupt', 'complete']);

it('rejects a changed accepted child response before consuming the parent interrupt', function () {
    [$manager, $runtime, , , $interrupts] = durabilityBoundaryManager(DurabilityBoundaryChildResumeCrashRuntime::class);
    app()->instance(AgentGraphManager::class, $manager);
    $manager->define(StateGraph::make('child_response_binding')
        ->state(['answer' => 'string'])
        ->node('ask', fn (NodeContext $context) => $context->hasResumePayload()
            ? NodeResult::end(['answer' => $context->resumePayload()['answer']])
            : NodeResult::interrupt('input'))
        ->edge(StateGraph::START, 'ask'));
    $manager->define(StateGraph::make('parent_response_binding')
        ->node('child', SubgraphNode::make('child', 'child_response_binding'))
        ->edge(StateGraph::START, 'child'));
    $parent = $manager->graph('parent_response_binding')->thread('child-response-binding')->run();
    $binding = $parent->interrupt()['payload'];
    $runtime->crashChildResume = true;

    expect(fn () => $manager->resume($binding['child_run_id'], [
        'interrupt_id' => $binding['child_interrupt_id'], 'answer' => 'accepted',
    ]))->toThrow(RuntimeException::class, 'Injected crash after child acceptance.');
    $parentResponse = [
        'interrupt_id' => $parent->interrupt()['interrupt_id'],
        'child_run_id' => $binding['child_run_id'],
        'child_interrupt_id' => $binding['child_interrupt_id'],
        'answer' => 'changed',
    ];

    expect(fn () => $manager->resume($parent->runId(), $parentResponse))
        ->toThrow(InvalidArgumentException::class, 'Child resume payload does not match');
    expect($interrupts->pendingForRun($parent->runId())['interrupt_id'])
        ->toBe($parent->interrupt()['interrupt_id']);
    $parentResponse['answer'] = 'accepted';
    $result = $manager->resume($parent->runId(), $parentResponse);

    expect($result->status())->toBe('completed')
        ->and($manager->inspect($binding['child_run_id'])->state('answer'))->toBe('accepted');
});

it('rejects child identity fields on an ordinary approval without consuming it', function () {
    [$manager, , , , $interrupts] = durabilityBoundaryManager();
    $effects = 0;
    durabilityBoundaryApprovalGraph($manager, $effects);
    $result = $manager->graph('approval_boundary')->thread('ordinary-approval')->run();

    expect(fn () => $manager->resume($result->runId(), [
        'interrupt_id' => $result->interrupt()['interrupt_id'], 'child_run_id' => 'foreign',
    ]))->toThrow(InvalidArgumentException::class, 'only valid for a pending subgraph interrupt');
    expect($interrupts->pendingForRun($result->runId())['interrupt_id'])
        ->toBe($result->interrupt()['interrupt_id'])
        ->and($effects)->toBe(0);
});

class DurabilityBoundaryChildResumeCrashRuntime extends GraphRuntime
{
    public bool $crashChildResume = false;

    protected function continueLocked(GraphDefinition $graph, array $run, array $state, array $nextNodes, array $resumeContext = [], ?RuntimeOptions $options = null): RunResult
    {
        if ($this->crashChildResume && $graph->key() === 'child_response_binding' && isset($resumeContext['resume_payload'])) {
            $this->crashChildResume = false;

            throw new RuntimeException('Injected crash after child acceptance.');
        }

        return parent::continueLocked($graph, $run, $state, $nextNodes, $resumeContext, $options);
    }
}

it('requires proof of accepted approval before redriving a legacy queued wait', function (bool $accepted, string $type, string $executionStatus) {
    config(['agent-graph.execution.mode' => 'queued_supersteps']);
    [$manager, , $runs, $checkpoints, $interrupts, $executions] = durabilityBoundaryManager();
    $effects = 0;
    durabilityBoundaryApprovalGraph($manager, $effects);
    $run = $runs->create('approval_boundary', '1', 'legacy-queued-wait');
    $checkpoint = $checkpoints->create([
        'run_id' => $run['public_id'], 'thread_id' => $run['thread_id'],
        'graph_key' => 'approval_boundary', 'graph_version' => '1', 'step' => 1,
        'state' => [], 'next_nodes' => ['approval'], 'completed_nodes' => ['approval'],
        'interrupts' => [],
        'meta' => ['runtime' => ['schedule' => ['next' => [['node' => 'effect', 'input' => [], 'meta' => []]]]]],
    ]);
    $interrupt = null;

    if ($accepted) {
        $interrupt = $interrupts->create([
            'run_id' => $run['public_id'], 'checkpoint_id' => $checkpoint['checkpoint_id'],
            'node_id' => 'approval', 'type' => $type, 'payload' => [],
        ]);
        $response = $type === 'state_edit' ? ['state' => ['approved' => true]] : ['approved' => true];
        $interrupts->resolvePending($interrupt['interrupt_id'], $run['public_id'], [
            'interrupt_id' => $interrupt['interrupt_id'], ...$response,
        ]);
    }

    $execution = $executions->schedule([
        'run_id' => $run['public_id'], 'checkpoint_id' => $checkpoint['checkpoint_id'],
        'step' => 2, 'node_id' => $accepted ? 'approval' : 'effect', 'status' => $executionStatus,
        'base_state' => $accepted ? ['approved' => true] : [],
        'node_state' => $accepted ? ['approved' => true] : [],
        'resume_payload' => $accepted ? ['approved' => true] : null,
        'interrupt_id' => $interrupt['interrupt_id'] ?? null,
    ]);

    if (! $accepted) {
        expect(fn () => $manager->recover($run['public_id']))
            ->toThrow(RuntimeException::class, 'inconsistent recovery schedule');

        if ($executionStatus === 'pending') {
            expect(fn () => $manager->executeQueuedNode($execution['execution_id']))
                ->toThrow(RuntimeException::class, 'inconsistent recovery schedule');
        } else {
            expect(fn () => $manager->continueQueuedSuperstep($run['public_id'], 2))
                ->toThrow(RuntimeException::class, 'inconsistent recovery schedule');
        }

        expect($effects)->toBe(0)
            ->and($runs->find($run['public_id'])['status'])->toBe('running');
        Queue::assertNotPushed(NodeExecutionJob::class);

        return;
    }

    $manager->recover($run['public_id']);

    foreach ([2, 3] as $step) {
        foreach ($executions->listForRunStep($run['public_id'], $step) as $nodeExecution) {
            $manager->executeQueuedNode($nodeExecution['execution_id']);
        }

        $result = $manager->continueQueuedSuperstep($run['public_id'], $step);
    }

    expect($result->status())->toBe('completed')
        ->and($effects)->toBe(1);
})->with([
    'unapproved pending successor' => [false, 'approval', 'pending'],
    'unapproved finished successor' => [false, 'approval', 'completed'],
    'accepted legacy approval' => [true, 'approval', 'pending'],
    'accepted legacy state edit' => [true, 'state_edit', 'pending'],
]);

it('does not start peers after a node failure was persisted before a worker crash', function () {
    config(['agent-graph.execution.mode' => 'queued_supersteps']);
    [$manager, , $runs, , , $executions] = durabilityBoundaryManager();
    $effects = 0;
    $manager->define(StateGraph::make('failed_frontier')
        ->node('failure', fn () => NodeResult::fail('Expected failure.'))
        ->node('effect', function () use (&$effects) {
            $effects++;

            return NodeResult::end();
        })->edge(StateGraph::START, 'failure')->edge(StateGraph::START, 'effect'));
    $run = $manager->graph('failed_frontier')->thread('failed-frontier')->run();
    [$failure, $peer] = $executions->listForRun($run->runId());
    $claim = $executions->claim($failure['execution_id'], now()->addMinute());
    $executions->fail($failure['execution_id'], $claim['claim_token'], ['message' => 'Persisted before process loss.']);
    Queue::fake();

    $manager->recover($run->runId());
    Queue::assertNotPushed(NodeExecutionJob::class);
    Queue::assertPushed(ContinueSuperstepJob::class, 1);
    // An already-delivered peer job must also respect the durable failure.
    $manager->executeQueuedNode($peer['execution_id']);

    expect($runs->find($run->runId())['status'])->toBe('failed')
        ->and($executions->find($peer['execution_id'])['status'])->toBe('pending')
        ->and($effects)->toBe(0);
});

it('validates nested child state before accepting any parent response', function (bool $nested) {
    [$manager, , , , $interrupts] = durabilityBoundaryManager();
    app()->instance(AgentGraphManager::class, $manager);
    $manager->define(StateGraph::make('typed_child')
        ->state(['answer' => 'int'])
        ->node('ask', fn (NodeContext $context) => $context->hasResumePayload()
            ? NodeResult::end(['answer' => $context->resumePayload()['answer']])
            : NodeResult::interrupt('input'))
        ->edge(StateGraph::START, 'ask'));

    if ($nested) {
        $manager->define(StateGraph::make('typed_middle')
            ->node('child', SubgraphNode::make('child', 'typed_child'))
            ->edge(StateGraph::START, 'child'));
    }

    $manager->define(StateGraph::make('typed_parent')
        ->node('child', SubgraphNode::make('child', $nested ? 'typed_middle' : 'typed_child'))
        ->edge(StateGraph::START, 'child'));
    $parent = $manager->graph('typed_parent')->thread('typed-parent')->run();
    $binding = $parent->interrupt()['payload'];
    $payload = [
        'interrupt_id' => $parent->interrupt()['interrupt_id'],
        'child_run_id' => $binding['child_run_id'],
        'child_interrupt_id' => $binding['child_interrupt_id'],
        'answer' => 'not-an-integer',
    ];

    expect(fn () => $manager->resume($parent->runId(), $payload))
        ->toThrow(InvalidArgumentException::class, 'must match schema type [int]');
    expect($interrupts->pendingForRun($parent->runId())['interrupt_id'])
        ->toBe($parent->interrupt()['interrupt_id'])
        ->and($interrupts->pendingForRun($binding['child_run_id'])['interrupt_id'])
        ->toBe($binding['child_interrupt_id']);
    $payload['answer'] = 7;

    expect($manager->resume($parent->runId(), $payload)->status())->toBe('completed');
})->with([false, true]);
