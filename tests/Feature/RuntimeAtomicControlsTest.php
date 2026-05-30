<?php

use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Contracts\CheckpointStore;
use Heiner\AgentGraph\Contracts\DelayScheduler;
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

it('cancels runs while holding the run lock', function () {
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
    $cancelled = $manager->cancel($run->runId());

    expect($cancelled->status())->toBe('cancelled')
        ->and($locks->keys)->toContain('agent-graph:run:'.$run->runId())
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
        delayScheduler: app(DelayScheduler::class),
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

    public function update(string $runId, array $attributes): array
    {
        if (! $this->locks->isActive('agent-graph:run:'.$runId)) {
            throw new RuntimeException("Run [{$runId}] was updated outside the run lock.");
        }

        $this->statuses[] = $attributes['status'] ?? null;

        return parent::update($runId, $attributes);
    }
}

final class RuntimeAtomicInterruptStore extends InMemoryInterruptStore
{
    public function __construct(private readonly RuntimeAtomicRecordingLockProvider $locks) {}

    public function resolve(string $interruptId, array $response, ?string $resolvedBy = null): array
    {
        $interrupt = $this->find($interruptId);
        $this->assertInsideRunLock($interruptId, $interrupt['run_id'] ?? null);

        return parent::resolve($interruptId, $response, $resolvedBy);
    }

    public function resolvePending(string $interruptId, string $runId, array $response, ?string $resolvedBy = null): array
    {
        $this->assertInsideRunLock($interruptId, $runId);

        return parent::resolvePending($interruptId, $runId, $response, $resolvedBy);
    }

    private function assertInsideRunLock(string $interruptId, ?string $runId): void
    {
        if ($runId === null || ! $this->locks->isActive('agent-graph:run:'.$runId)) {
            throw new RuntimeException("Interrupt [{$interruptId}] was resolved outside the run lock.");
        }
    }
}
