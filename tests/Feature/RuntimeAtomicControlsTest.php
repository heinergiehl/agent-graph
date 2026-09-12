<?php

use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Contracts\CheckpointStore;
use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Contracts\MemoryStore;
use Heiner\AgentGraph\Contracts\NodeExecutionStore;
use Heiner\AgentGraph\Contracts\RunStore;
use Heiner\AgentGraph\Contracts\TaskStore;
use Heiner\AgentGraph\Contracts\TraceStore;
use Heiner\AgentGraph\Contracts\WriteStore;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Persistence\InMemoryInterruptStore;
use Heiner\AgentGraph\Persistence\InMemoryRunStore;
use Heiner\AgentGraph\Runtime\GraphRuntime;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunEventDispatcher;
use Heiner\AgentGraph\Support\DelaySchedulerResolver;
use Illuminate\Support\Facades\Queue;

it('releases coordination locks before invoking nodes for start and resume', function (bool $session, bool $stateEdit) {
    $locks = new RuntimeAtomicRecordingLockProvider;
    $manager = runtimeAtomicManager($locks, new RuntimeAtomicInterruptStore($locks));
    $observed = [];
    $manager->define(StateGraph::make('short_locks')->state(['answer' => 'string|null'])
        ->node('ask', function (NodeContext $context) use ($locks, &$observed, $stateEdit) {
            $observed[] = $locks->activeKey;

            return $context->state('answer') !== null
                ? NodeResult::end()
                : NodeResult::interrupt($stateEdit ? 'state_edit' : 'input', []);
        })->edge(StateGraph::START, 'ask'));
    $pending = $session
        ? $manager->runSession('short_locks', 'session')
        : $manager->graph('short_locks')->run();
    $result = $stateEdit
        ? $manager->resumeWithStateEdit($pending->runId(), $pending->interrupt()['interrupt_id'], ['answer' => 'done'])
        : $manager->resume($pending->runId(), ['interrupt_id' => $pending->interrupt()['interrupt_id'], 'answer' => 'done']);
    expect($result->completed())->toBeTrue()->and($observed)->toBe([null, null]);
})->with([[false, false], [true, false], [false, true], [true, true]]);

it('releases the recovery lock before delivering a persisted frontier', function () {
    Queue::fake();
    config()->set('agent-graph.execution.mode', 'queued_supersteps');
    $locks = new RuntimeAtomicRecordingLockProvider;
    $manager = runtimeAtomicManager($locks, new RuntimeAtomicInterruptStore($locks));
    $observed = [];
    $manager->define(StateGraph::make('recover_locks')
        ->node('work', function () use ($locks, &$observed) {
            $observed[] = $locks->activeKey;

            return NodeResult::end();
        })->edge(StateGraph::START, 'work'));
    $pending = $manager->graph('recover_locks')->run();
    config()->set('agent-graph.execution.mode', 'sync');
    expect($manager->recover($pending->runId())->completed())->toBeTrue()
        ->and($observed)->toBe([null]);
});

it('returns the committed result when queued delivery uses the synchronous queue driver', function () {
    config()->set('agent-graph.execution.mode', 'queued_supersteps');
    config()->set('queue.default', 'sync');
    $manager = app(AgentGraphManager::class);
    $manager->define(StateGraph::make('inline_queue')
        ->state(['answer' => 'string'])
        ->node('work', fn () => NodeResult::end(['answer' => 'done']))
        ->edge(StateGraph::START, 'work'));
    $result = $manager->graph('inline_queue')->run();
    expect($result->completed())->toBeTrue()->and($result->state('answer'))->toBe('done');
});

it('resolves resume interrupts while holding the run lock', function () {
    $locks = new RuntimeAtomicRecordingLockProvider;
    $interrupts = new RuntimeAtomicInterruptStore($locks);
    $manager = runtimeAtomicManager($locks, $interrupts);

    $manager->define(
        StateGraph::make('atomic_resume_graph')
            ->state(['answer' => 'string|null'])
            ->node('ask', RuntimeAtomicAskNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', StateGraph::END)
            ->compile(),
    );

    $run = $manager->graph('atomic_resume_graph')->thread('atomic-resume')->run();
    $completed = $manager->resume($run->runId(), [
        'interrupt_id' => $run->interrupt()['interrupt_id'],
        'answer' => 'done',
    ]);

    expect($completed->status())->toBe('completed')
        ->and($locks->keys)->toContain('agent-graph:run:'.$run->runId());
});

it('resolves state edit interrupts while holding the run lock', function () {
    $locks = new RuntimeAtomicRecordingLockProvider;
    $interrupts = new RuntimeAtomicInterruptStore($locks);
    $manager = runtimeAtomicManager($locks, $interrupts);

    $manager->define(
        StateGraph::make('atomic_state_edit_graph')
            ->state(['draft' => 'string|null', 'approved' => 'bool|null'])
            ->node('review', RuntimeAtomicStateEditNode::class)
            ->edge(StateGraph::START, 'review')
            ->edge('review', StateGraph::END)
            ->compile(),
    );

    $run = $manager->graph('atomic_state_edit_graph')
        ->thread('atomic-state-edit')
        ->input(['draft' => null])
        ->run();

    $completed = $manager->resumeWithStateEdit(
        $run->runId(),
        $run->interrupt()['interrupt_id'],
        ['draft' => 'approved copy'],
        'reviewer-1',
    );

    expect($completed->status())->toBe('completed')
        ->and($completed->state('approved'))->toBeTrue()
        ->and($locks->keys)->toContain('agent-graph:run:'.$run->runId());
});

it('cancels runs through an atomic transition without taking the run lock', function () {
    $locks = new RuntimeAtomicRecordingLockProvider;
    $runs = new RuntimeAtomicRunStore($locks);
    $interrupts = new RuntimeAtomicInterruptStore($locks);
    $manager = runtimeAtomicManager($locks, $interrupts, $runs);

    $manager->define(
        StateGraph::make('atomic_cancel_graph')
            ->state(['answer' => 'string|null'])
            ->node('ask', RuntimeAtomicAskNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', StateGraph::END)
            ->compile(),
    );

    $run = $manager->graph('atomic_cancel_graph')->thread('atomic-cancel')->run();
    $lockCount = count($locks->keys);
    $cancelled = $manager->cancel($run->runId());

    expect($cancelled->status())->toBe('cancelled')
        ->and($locks->keys)->toHaveCount($lockCount)
        ->and($runs->statuses)->toContain('cancelled');
});

function runtimeAtomicManager(RuntimeAtomicRecordingLockProvider $locks, InterruptStore $interrupts, ?RunStore $runs = null): AgentGraphManager
{
    $runtime = new GraphRuntime(
        container: app(),
        runs: $runs ?? app(RunStore::class),
        checkpoints: app(CheckpointStore::class),
        writes: app(WriteStore::class),
        tasks: app(TaskStore::class),
        interrupts: $interrupts,
        memory: app(MemoryStore::class),
        traces: app(TraceStore::class),
        locks: $locks,
        delaySchedulers: app(DelaySchedulerResolver::class),
        events: app(RunEventDispatcher::class),
        nodeExecutions: app(NodeExecutionStore::class),
    );

    return new AgentGraphManager($runtime, app(RunEventDispatcher::class));
}

final class RuntimeAtomicAskNode
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->hasResumePayload()) {
            return NodeResult::end(['answer' => (string) $context->state('answer')]);
        }

        return NodeResult::interrupt('input', ['prompt' => 'Answer']);
    }
}

final class RuntimeAtomicStateEditNode
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->state('draft') === null) {
            return NodeResult::interrupt('state_edit', ['title' => 'Edit draft']);
        }

        return NodeResult::write(['approved' => true]);
    }
}

final class RuntimeAtomicRecordingLockProvider implements LockProvider
{
    public array $keys = [];

    public ?string $activeKey = null;

    public function withLock(string $key, Closure $callback): mixed
    {
        $previous = $this->activeKey;
        $this->activeKey = $key;
        $this->keys[] = $key;

        try {
            return $callback();
        } finally {
            $this->activeKey = $previous;
        }
    }

    public function isActive(string $key): bool
    {
        return $this->activeKey === $key;
    }
}

final class RuntimeAtomicRunStore extends InMemoryRunStore
{
    public array $statuses = [];

    public function __construct(private readonly RuntimeAtomicRecordingLockProvider $locks) {}

    public function transition(string $runId, int $revision, array $attributes): array
    {
        if (($attributes['status'] ?? null) !== 'cancelled' && ! $this->locks->isActive('agent-graph:run:'.$runId)) {
            throw new RuntimeException("Run [{$runId}] was updated outside the run lock.");
        }

        $this->statuses[] = $attributes['status'] ?? null;

        return parent::transition($runId, $revision, $attributes);
    }
}

final class RuntimeAtomicInterruptStore extends InMemoryInterruptStore
{
    public function __construct(private readonly RuntimeAtomicRecordingLockProvider $locks) {}

    public function resolve(string $interruptId, array $response, ?string $resolvedBy = null): array
    {
        $interrupt = $this->find($interruptId);
        if (($response['type'] ?? null) !== 'cancelled') {
            $this->assertInsideRunLock($interruptId, $interrupt['run_id'] ?? null);
        }

        return parent::resolve($interruptId, $response, $resolvedBy);
    }

    public function resolvePending(string $interruptId, string $runId, array $response, ?string $resolvedBy = null): array
    {
        if (($response['type'] ?? null) !== 'cancelled') {
            $this->assertInsideRunLock($interruptId, $runId);
        }

        return parent::resolvePending($interruptId, $runId, $response, $resolvedBy);
    }

    private function assertInsideRunLock(string $interruptId, ?string $runId): void
    {
        if ($runId === null || ! $this->locks->isActive('agent-graph:run:'.$runId)) {
            throw new RuntimeException("Interrupt [{$interruptId}] was resolved outside the run lock.");
        }
    }
}
