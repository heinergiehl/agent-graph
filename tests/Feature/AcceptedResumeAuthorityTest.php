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
use Heiner\AgentGraph\Events\GraphEvent;
use Heiner\AgentGraph\Events\GraphResumed;
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
use Heiner\AgentGraph\Runtime\SubgraphNode;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
    config(['agent-graph.database.connection' => 'testing']);
    foreach ([
        RunStore::class => DatabaseRunStore::class,
        CheckpointStore::class => DatabaseCheckpointStore::class,
        WriteStore::class => DatabaseWriteStore::class,
        TaskStore::class => DatabaseTaskStore::class,
        InterruptStore::class => DatabaseInterruptStore::class,
        MemoryStore::class => DatabaseMemoryStore::class,
        NodeExecutionStore::class => DatabaseNodeExecutionStore::class,
        TraceStore::class => DatabaseTraceStore::class,
    ] as $contract => $implementation) {
        app()->singleton($contract, $implementation);
        app()->forgetInstance($contract);
    }
    app()->forgetInstance(GraphRuntime::class);
    app()->forgetInstance(AgentGraphManager::class);
    app()->instance(LockProvider::class, new AcceptedResumeAuthorityNonLockingProvider);
});

it('recovers exactly the accepted nested response after acceptance loses its execution stack', function (): void {
    [$manager, $parent, $payload, $childRunId, $probe] = acceptedResumeAuthorityFixture();

    acceptedResumeAuthorityAbandonAt($parent->runId(), fn (): mixed => $manager->resume($parent->runId(), $payload));

    $accepted = $manager->inspect($parent->runId());
    expect($accepted->status())->toBe('running')
        ->and($accepted->interrupt())->toBeNull()
        ->and(data_get($accepted->run(), 'meta.runtime.recovery.pending_resume.interrupt_id'))->toBe($payload['interrupt_id'])
        ->and(app(InterruptStore::class)->find($payload['interrupt_id'])['response'])->toBe($payload)
        ->and($probe->effects)->toBe([]);

    $recovered = $manager->recover($parent->runId());

    expect($recovered->completed())->toBeTrue()
        ->and($manager->inspect($childRunId)->status())->toBe('completed')
        ->and($manager->inspect($childRunId)->state('answer'))->toBe('accepted')
        ->and($probe->effects)->toBe(['accepted'])
        ->and(data_get($manager->inspect($parent->runId())->run(), 'meta.runtime.recovery.pending_resume'))->toBeNull();

    expect($manager->recover($parent->runId())->completed())->toBeTrue()
        ->and($probe->effects)->toBe(['accepted']);
});

it('does not let an accepted resume authorize a changed redelivery', function (): void {
    [$manager, $parent, $payload, $childRunId, $probe] = acceptedResumeAuthorityFixture();
    acceptedResumeAuthorityAbandonAt($parent->runId(), fn (): mixed => $manager->resume($parent->runId(), $payload));
    $before = acceptedResumeAuthoritySnapshot($parent->runId(), $childRunId);
    $changed = [...$payload, 'answer' => 'changed'];

    acceptedResumeAuthorityRejects(fn (): mixed => $manager->resume($parent->runId(), $changed));
    expect(acceptedResumeAuthoritySnapshot($parent->runId(), $childRunId))->toBe($before)
        ->and($probe->effects)->toBe([]);

    expect($manager->resume($parent->runId(), $payload)->completed())->toBeTrue()
        ->and($probe->effects)->toBe(['accepted']);
});

it('recovers a parent whose child already accepted the same nested response', function (): void {
    [$manager, $parent, $payload, $childRunId, $probe] = acceptedResumeAuthorityFixture();

    acceptedResumeAuthorityAbandonAt($childRunId, fn (): mixed => $manager->resume($childRunId, [
        'interrupt_id' => $payload['child_interrupt_id'],
        'answer' => 'accepted',
    ]));
    expect(data_get($manager->inspect($childRunId)->run(), 'meta.runtime.recovery.pending_resume.interrupt_id'))
        ->toBe($payload['child_interrupt_id']);

    acceptedResumeAuthorityAbandonAt($parent->runId(), fn (): mixed => $manager->resume($parent->runId(), $payload));

    expect($manager->recover($parent->runId())->completed())->toBeTrue()
        ->and($manager->inspect($childRunId)->status())->toBe('completed')
        ->and($probe->effects)->toBe(['accepted']);
});

it('allows a parent to advance its child to a newer wait and then resume that wait', function (): void {
    [$manager, $parent, $payload, $childRunId, $probe] = acceptedResumeAuthorityFixture();
    $first = $manager->resume($parent->runId(), [...$payload, 'answer' => 'advance-to-new-wait']);

    expect($first->interrupted())->toBeTrue()
        ->and($first->interrupt()['payload']['child_run_id'])->toBe($childRunId)
        ->and($first->interrupt()['payload']['child_interrupt_id'])->not->toBe($payload['child_interrupt_id']);

    $completed = $manager->resume($parent->runId(), [
        'interrupt_id' => $first->interrupt()['interrupt_id'],
        'child_run_id' => $first->interrupt()['payload']['child_run_id'],
        'child_interrupt_id' => $first->interrupt()['payload']['child_interrupt_id'],
        'answer' => 'accepted',
    ]);

    expect($completed->completed())->toBeTrue()
        ->and($manager->inspect($childRunId)->state('answer'))->toBe('accepted')
        ->and($probe->effects)->toBe(['accepted']);
});

it('rejects queued parent receipts with changed child authority before a worker claim', function (string $mutation): void {
    [$manager, $parent, $payload, $childRunId, $probe] = acceptedResumeAuthorityQueuedFixture();
    acceptedResumeAuthorityCorrupt($mutation, $manager, $parent->runId(), $childRunId, $payload);
    $receipt = acceptedResumeAuthorityQueuedReceipt($manager, $parent->runId());
    $before = acceptedResumeAuthoritySnapshot($parent->runId(), $childRunId);

    acceptedResumeAuthorityRejects(fn (): mixed => $manager->executeQueuedNode($receipt['execution_id']));

    expect(acceptedResumeAuthoritySnapshot($parent->runId(), $childRunId))->toBe($before)
        ->and(app(NodeExecutionStore::class)->find($receipt['execution_id'])['status'])->toBe('pending')
        ->and($probe->effects)->toBe([]);
})->with([
    'child parent' => 'child-parent',
    'accepted response' => 'resolved-response',
    'registered child graph' => 'registered-child-version',
]);

it('rejects a completed queued receipt when historical child authority changes before commit', function (string $mutation): void {
    [$manager, $parent, $payload, $childRunId, $probe] = acceptedResumeAuthorityQueuedFixture();
    $receipt = acceptedResumeAuthorityQueuedReceipt($manager, $parent->runId());

    config(['agent-graph.execution.mode' => 'sync']);
    $manager->executeQueuedNode($receipt['execution_id']);
    expect($manager->inspect($parent->runId())->status())->toBe('running')
        ->and(app(NodeExecutionStore::class)->find($receipt['execution_id'])['status'])->toBe('completed')
        ->and($manager->inspect($childRunId)->status())->toBe('completed');

    acceptedResumeAuthorityCorrupt($mutation, $manager, $parent->runId(), $childRunId, $payload);
    $before = acceptedResumeAuthoritySnapshot($parent->runId(), $childRunId);

    acceptedResumeAuthorityRejects(fn (): mixed => $manager->continueQueuedSuperstep($parent->runId(), $receipt['step']));

    expect(acceptedResumeAuthoritySnapshot($parent->runId(), $childRunId))->toBe($before)
        ->and($probe->effects)->toBe(['accepted']);
})->with([
    'static child parent' => 'child-parent',
    'historical child response' => 'child-historical-response',
    'historical child checkpoint' => 'child-historical-checkpoint',
]);

it('redelivers an expired root receipt after its middle child accepted the nested answer', function (): void {
    [$manager, $root, $payload, $middleRunId, $leafRunId, $probe] = acceptedResumeAuthorityNestedFixture();

    acceptedResumeAuthorityAbandonAt($middleRunId, fn (): mixed => $manager->resume($root->runId(), $payload));
    $executions = $manager->nodeExecutions($root->runId());
    $receipt = $executions[array_key_last($executions)];

    expect($receipt['status'])->toBe('running')
        ->and(data_get($manager->inspect($middleRunId)->run(), 'meta.runtime.recovery.pending_resume.interrupt_id'))
        ->toBe($payload['child_interrupt_id']);

    $this->travel(301)->seconds();
    $completed = $manager->resume($root->runId(), $payload);

    expect($completed->completed())->toBeTrue()
        ->and($manager->inspect($middleRunId)->status())->toBe('completed')
        ->and($manager->inspect($leafRunId)->status())->toBe('completed')
        ->and($probe->effects)->toBe(['accepted']);
});

it('rejects changed nested identities in a completed child response before parent commit', function (): void {
    [$manager, $root, $payload, $middleRunId, $leafRunId, $probe] = acceptedResumeAuthorityNestedFixture();
    config(['agent-graph.execution.mode' => 'queued_supersteps']);
    Queue::fake();
    $manager->resume($root->runId(), $payload);
    $receipt = acceptedResumeAuthorityQueuedReceipt($manager, $root->runId());
    config(['agent-graph.execution.mode' => 'sync']);
    $manager->executeQueuedNode($receipt['execution_id']);
    expect($manager->inspect($root->runId())->status())->toBe('running')
        ->and($manager->inspect($middleRunId)->status())->toBe('completed');

    $interrupts = app(InterruptStore::class);
    $response = $interrupts->find($payload['child_interrupt_id'])['response'];
    $response['child_run_id'] = 'run_foreign';
    $interrupts->resolve($payload['child_interrupt_id'], $response);
    $before = acceptedResumeAuthoritySnapshot($root->runId(), $middleRunId);
    acceptedResumeAuthorityRejects(fn () => $manager->continueQueuedSuperstep($root->runId(), $receipt['step']));
    expect(acceptedResumeAuthoritySnapshot($root->runId(), $middleRunId))->toBe($before)
        ->and($probe->effects)->toBe(['accepted']);
});

it('rejects corrupted accepted-resume authority before creating recovery receipts or effects', function (string $mutation): void {
    [$manager, $parent, $payload, $childRunId, $probe] = acceptedResumeAuthorityFixture();
    acceptedResumeAuthorityAbandonAt($parent->runId(), fn (): mixed => $manager->resume($parent->runId(), $payload));

    acceptedResumeAuthorityCorrupt($mutation, $manager, $parent->runId(), $childRunId, $payload);
    $before = acceptedResumeAuthoritySnapshot($parent->runId(), $childRunId);

    acceptedResumeAuthorityRejects(fn (): mixed => $manager->recover($parent->runId()));
    expect(acceptedResumeAuthoritySnapshot($parent->runId(), $childRunId))->toBe($before)
        ->and($probe->effects)->toBe([]);
})->with([
    'child parent binding' => 'child-parent',
    'child node binding' => 'child-node',
    'child relationship binding' => 'child-relationship',
    'parent graph version' => 'parent-run-version',
    'child graph version' => 'child-run-version',
    'registered child graph version' => 'registered-child-version',
    'source checkpoint binding' => 'source-checkpoint',
    'resolved interrupt checkpoint binding' => 'resolved-interrupt-checkpoint',
    'newer child wait' => 'newer-child-wait',
]);

/** @return array{AgentGraphManager, RunResult, array<string, string>, string, object} */
function acceptedResumeAuthorityFixture(): array
{
    $manager = app(AgentGraphManager::class);
    $probe = new class
    {
        /** @var list<string> */
        public array $effects = [];
    };

    $manager->define(StateGraph::make('accepted-resume-authority-child')
        ->state(['answer' => 'string|null'])
        ->node('ask', function (NodeContext $context) use ($probe): NodeResult {
            if (! $context->hasResumePayload()) {
                return NodeResult::interrupt('input', ['question' => 'Answer?']);
            }

            $answer = (string) $context->resumePayload()['answer'];

            if ($answer === 'advance-to-new-wait') {
                return NodeResult::interrupt('next_input', ['question' => 'A newer answer?']);
            }

            $probe->effects[] = $answer;

            return NodeResult::end(['answer' => $answer]);
        })
        ->edge(StateGraph::START, 'ask')
        ->edge('ask', StateGraph::END));
    $manager->define(StateGraph::make('accepted-resume-authority-parent')
        ->node('child', SubgraphNode::make('child', 'accepted-resume-authority-child'))
        ->edge(StateGraph::START, 'child')
        ->edge('child', StateGraph::END));

    $parent = $manager->graph('accepted-resume-authority-parent')->thread('accepted-resume-authority')->run();
    $binding = $parent->interrupt()['payload'];
    $payload = [
        'interrupt_id' => $parent->interrupt()['interrupt_id'],
        'child_run_id' => $binding['child_run_id'],
        'child_interrupt_id' => $binding['child_interrupt_id'],
        'answer' => 'accepted',
    ];

    return [$manager, $parent, $payload, $binding['child_run_id'], $probe];
}

/** @return array{AgentGraphManager, RunResult, array<string, string>, string, object} */
function acceptedResumeAuthorityQueuedFixture(): array
{
    config(['agent-graph.execution.mode' => 'sync']);
    [$manager, $parent, $payload, $childRunId, $probe] = acceptedResumeAuthorityFixture();

    config(['agent-graph.execution.mode' => 'queued_supersteps']);
    Queue::fake();
    $accepted = $manager->resume($parent->runId(), $payload);

    expect($accepted->status())->toBe('running')
        ->and(data_get($manager->inspect($parent->runId())->run(), 'meta.runtime.recovery.pending_resume'))->toBeNull();

    return [$manager, $parent, $payload, $childRunId, $probe];
}

/** @return array{AgentGraphManager, RunResult, array<string, string>, string, string, object} */
function acceptedResumeAuthorityNestedFixture(): array
{
    $manager = app(AgentGraphManager::class);
    $probe = new class
    {
        /** @var list<string> */
        public array $effects = [];
    };

    $manager->define(StateGraph::make('accepted-resume-authority-leaf')
        ->state(['answer' => 'string|null'])
        ->node('ask', function (NodeContext $context) use ($probe): NodeResult {
            if (! $context->hasResumePayload()) {
                return NodeResult::interrupt('input', ['question' => 'Answer?']);
            }

            $answer = (string) $context->resumePayload()['answer'];
            $probe->effects[] = $answer;

            return NodeResult::end(['answer' => $answer]);
        })
        ->edge(StateGraph::START, 'ask')
        ->edge('ask', StateGraph::END));
    $manager->define(StateGraph::make('accepted-resume-authority-middle')
        ->node('leaf', SubgraphNode::make('leaf', 'accepted-resume-authority-leaf'))
        ->edge(StateGraph::START, 'leaf')
        ->edge('leaf', StateGraph::END));
    $manager->define(StateGraph::make('accepted-resume-authority-root')
        ->node('middle', SubgraphNode::make('middle', 'accepted-resume-authority-middle'))
        ->edge(StateGraph::START, 'middle')
        ->edge('middle', StateGraph::END));

    $root = $manager->graph('accepted-resume-authority-root')->thread('accepted-resume-authority-nested')->run();
    $rootBinding = $root->interrupt()['payload'];
    $middle = $manager->inspect($rootBinding['child_run_id']);
    $middleBinding = $middle->interrupt()['payload'];

    return [$manager, $root, [
        'interrupt_id' => $root->interrupt()['interrupt_id'],
        'child_run_id' => $rootBinding['child_run_id'],
        'child_interrupt_id' => $rootBinding['child_interrupt_id'],
        'answer' => 'accepted',
    ], $rootBinding['child_run_id'], $middleBinding['child_run_id'], $probe];
}

/** @return array<string, mixed> */
function acceptedResumeAuthorityQueuedReceipt(AgentGraphManager $manager, string $runId): array
{
    $executions = $manager->nodeExecutions($runId);
    $receipt = $executions[array_key_last($executions)];

    expect($receipt['status'])->toBe('pending')
        ->and($receipt['interrupt_id'])->not->toBeNull()
        ->and($receipt['resume_payload'])->toBeArray();

    return $receipt;
}

/** @param callable(): mixed $resume */
function acceptedResumeAuthorityAbandonAt(string $runId, callable $resume): void
{
    app('events')->listen(GraphResumed::class, static function (GraphEvent $event) use ($runId): void {
        if ($event->runId === $runId) {
            Fiber::suspend();
        }
    });
    $fiber = new Fiber($resume);

    try {
        $fiber->start();
        expect($fiber->isSuspended())->toBeTrue();
    } finally {
        app('events')->forget(GraphResumed::class);
        unset($fiber);
    }
}

/** @param callable(): mixed $operation */
function acceptedResumeAuthorityRejects(callable $operation): void
{
    try {
        $operation();
    } catch (InvalidArgumentException|RuntimeException) {
        return;
    }

    throw new RuntimeException('Accepted recovery authority was not rejected.');
}

/** @return array<string, mixed> */
function acceptedResumeAuthoritySnapshot(string $parentRunId, string $childRunId): array
{
    return [
        'parent' => app(RunStore::class)->find($parentRunId),
        'child' => app(RunStore::class)->find($childRunId),
        'parent_interrupts' => app(InterruptStore::class)->listForRun($parentRunId),
        'child_interrupts' => app(InterruptStore::class)->listForRun($childRunId),
        'parent_checkpoints' => app(CheckpointStore::class)->listForRun($parentRunId),
        'child_checkpoints' => app(CheckpointStore::class)->listForRun($childRunId),
        'parent_executions' => app(NodeExecutionStore::class)->listForRun($parentRunId),
        'child_executions' => app(NodeExecutionStore::class)->listForRun($childRunId),
    ];
}

/** @param array<string, string> $payload */
function acceptedResumeAuthorityCorrupt(string $mutation, AgentGraphManager $manager, string $parentRunId, string $childRunId, array $payload): void
{
    $runs = app(RunStore::class);

    if ($mutation === 'resolved-response') {
        app(InterruptStore::class)->resolve($payload['interrupt_id'], [...$payload, 'answer' => 'foreign']);

        return;
    }

    if (in_array($mutation, ['child-parent', 'child-node', 'child-relationship'], true)) {
        $child = $runs->find($childRunId);
        $meta = $child['meta'];
        data_set($meta, match ($mutation) {
            'child-parent' => 'parent.run_id',
            'child-node' => 'parent.node_id',
            default => 'parent.relationship',
        }, 'foreign');
        $runs->update($childRunId, ['meta' => $meta]);

        return;
    }

    if ($mutation === 'parent-run-version' || $mutation === 'child-run-version') {
        $runs->update($mutation === 'parent-run-version' ? $parentRunId : $childRunId, ['graph_version' => 'foreign']);

        return;
    }

    if ($mutation === 'registered-child-version') {
        $manager->define(StateGraph::make('accepted-resume-authority-child', 'foreign')
            ->state(['answer' => 'string|null'])
            ->node('unexpected', static fn (): NodeResult => NodeResult::end())
            ->edge(StateGraph::START, 'unexpected'));

        return;
    }

    if ($mutation === 'source-checkpoint') {
        $parent = $runs->find($parentRunId);
        $meta = $parent['meta'];
        data_set($meta, 'runtime.recovery.pending_resume.source_checkpoint_id', 'chk_foreign');
        $runs->update($parentRunId, ['meta' => $meta]);

        return;
    }

    if ($mutation === 'resolved-interrupt-checkpoint') {
        app('db')->table(config('agent-graph.tables.interrupts', 'agent_graph_interrupts'))
            ->where('interrupt_id', $payload['interrupt_id'])
            ->update(['checkpoint_id' => 'chk_foreign']);

        return;
    }

    if ($mutation === 'child-historical-response') {
        app(InterruptStore::class)->resolve($payload['child_interrupt_id'], [
            'interrupt_id' => $payload['child_interrupt_id'],
            'answer' => 'foreign',
        ]);

        return;
    }

    if ($mutation === 'child-historical-checkpoint') {
        app('db')->table(config('agent-graph.tables.interrupts', 'agent_graph_interrupts'))
            ->where('interrupt_id', $payload['child_interrupt_id'])
            ->update(['checkpoint_id' => 'chk_foreign']);

        return;
    }

    if ($mutation === 'newer-child-wait') {
        $manager->resume($childRunId, [
            'interrupt_id' => $payload['child_interrupt_id'],
            'answer' => 'advance-to-new-wait',
        ]);

        return;
    }

    throw new RuntimeException("Unknown accepted-resume authority mutation [{$mutation}].");
}

final class AcceptedResumeAuthorityNonLockingProvider implements LockProvider
{
    public function withLock(string $key, Closure $callback): mixed
    {
        return $callback();
    }
}
