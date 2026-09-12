<?php

use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Events\GraphNodeRetrying;
use Heiner\AgentGraph\Exceptions\AgentApprovalRequiredException;
use Heiner\AgentGraph\Exceptions\NodeExecutionClaimLostException;
use Heiner\AgentGraph\Exceptions\NodeTimeoutException;
use Heiner\AgentGraph\Exceptions\RunStateChangedException;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Persistence\InMemoryMemoryStore;
use Heiner\AgentGraph\Persistence\InMemoryNodeExecutionStore;
use Heiner\AgentGraph\Persistence\InMemoryRunStore;
use Heiner\AgentGraph\Persistence\InMemoryTaskStore;
use Heiner\AgentGraph\Persistence\InMemoryTraceStore;
use Heiner\AgentGraph\Runtime\ExecutionAuthority;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeExecutor;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunEventDispatcher;

it('keeps an immutable revision fence even when a newer running snapshot exists', function () {
    [, $runs, $run, $authority] = nodeExecutorFixture();
    $authority->assertCurrent();
    $replacement = $runs->update($run['public_id'], ['meta' => ['owner' => 'replacement']]);

    expect(fn () => $authority->assertCurrent())->toThrow(RunStateChangedException::class)
        ->and($authority->revision())->toBe(0)
        ->and($run)->not->toHaveKeys(['_deadline', '_execution_id', '_claim_token'])
        ->and($runs->find($run['public_id']))->toEqual($replacement);
});

it('rejects reclaimed claims and claims belonging to a different run', function () {
    [, $runs, $run] = nodeExecutorFixture();
    $receipts = new InMemoryNodeExecutionStore;
    $receipt = $receipts->schedule(['run_id' => $run['public_id'], 'node_id' => 'work', 'step' => 1]);
    $old = $receipts->claim($receipt['execution_id'], now()->subSecond());
    $oldAuthority = new ExecutionAuthority($runs, $run, $receipts, $receipt['execution_id'], $old['claim_token']);
    $oldAuthority->assertCurrent();
    $winner = $receipts->claim($receipt['execution_id'], now()->addMinute());

    expect(fn () => $oldAuthority->assertCurrent())->toThrow(NodeExecutionClaimLostException::class);
    $current = new ExecutionAuthority($runs, $run, $receipts, $receipt['execution_id'], $winner['claim_token']);
    $current->assertCurrent();
    $other = $runs->create('executor', '1', 'another-thread');
    expect(fn () => (new ExecutionAuthority($runs, $other, $receipts, $receipt['execution_id'], $winner['claim_token']))->assertCurrent())
        ->toThrow(NodeExecutionClaimLostException::class);
    $receipts->complete($receipt['execution_id'], $winner['claim_token'], ['writes' => []]);
    expect(fn () => $current->assertCurrent())->toThrow(NodeExecutionClaimLostException::class);
});

it('revokes descendant admission when an ancestor is cancelled', function () {
    [, $runs, $parent] = nodeExecutorFixture();
    $child = $runs->create('child', '1', 'thread', [], ['parent' => ['run_id' => $parent['public_id'], 'relationship' => 'subgraph']]);
    $grandchild = $runs->create('grandchild', '1', 'thread', [], ['parent' => ['run_id' => $child['public_id'], 'relationship' => 'subgraph']]);
    $authority = new ExecutionAuthority($runs, $grandchild);
    $runs->update($parent['public_id'], ['status' => 'cancelled']);

    expect(fn () => $authority->assertCurrent())->toThrow(RunStateChangedException::class)
        ->and($runs->find($grandchild['public_id'])['status'])->toBe('cancelled')
        ->and($runs->find($child['public_id'])['status'])->toBe('running');
});

it('separates elapsed deadlines from ownership and never extends a shared deadline', function () {
    [, $runs, $run] = nodeExecutorFixture();
    $deadline = hrtime(true) / 1e9 - 1;
    $authority = new ExecutionAuthority($runs, $run, deadline: $deadline);
    $bounded = $authority->withTimeout(30);
    $bounded->assertCurrent();

    expect($bounded->deadline())->toBe($deadline)
        ->and($bounded->remainingSeconds())->toBe(0.0)
        ->and(fn () => $bounded->assertActive())->toThrow(NodeTimeoutException::class)
        ->and($runs->find($run['public_id']))->toEqual($run);
});

it('builds compatible node contexts and preserves array result and retry metadata semantics', function () {
    [$executor, , $run, $authority] = nodeExecutorFixture();
    $calls = 0;
    $contexts = [];
    $graph = StateGraph::make('executor')->node('work', function (NodeContext $context) use (&$calls, &$contexts) {
        $contexts[] = [$context->state(), $context->checkpointId(), $context->resumePayload(), $context->interruptId(), $context->threadId()];
        if (++$calls === 1) {
            throw new RuntimeException('Transient error');
        }

        return ['answer' => 'done'];
    })->retry('work', 3)->edge(StateGraph::START, 'work')->compile();
    $result = $executor->execute($graph, 'work', ['question' => 'test'], $run, $authority, 'cp_1', ['approved' => true], 'int_1');

    expect($result->writes())->toBe(['answer' => 'done'])
        ->and(data_get($result->meta(), 'runtime.retry'))->toBe(['attempts' => 2, 'max_attempts' => 3, 'failed_attempts' => 1])
        ->and($contexts)->toBe(array_fill(0, 2, [['question' => 'test'], 'cp_1', ['approved' => true], 'int_1', $run['thread_id']]));
});

it('does not retry approval, ownership or deadline failures', function (Throwable $failure) {
    [$executor, , $run, $authority] = nodeExecutorFixture();
    $calls = 0;
    $retryDecisions = 0;
    $graph = StateGraph::make('executor')->node('work', function () use (&$calls, $failure) {
        $calls++;
        throw $failure;
    })->retry('work', 3, when: function () use (&$retryDecisions) {
        $retryDecisions++;

        return true;
    })->edge(StateGraph::START, 'work')->compile();

    expect(fn () => $executor->execute($graph, 'work', [], $run, $authority))->toThrow($failure::class)
        ->and($calls)->toBe(1)->and($retryDecisions)->toBe(0);
})->with([
    'approval' => [new AgentApprovalRequiredException('work')],
    'run revision' => [new RunStateChangedException('changed')],
    'node claim' => [new NodeExecutionClaimLostException('changed')],
    'deadline' => [new NodeTimeoutException('elapsed')],
]);

it('checks cancellation again after retry observers before the next invocation', function () {
    [$executor, $runs, $run, $authority] = nodeExecutorFixture();
    $calls = 0;
    app('events')->listen(GraphNodeRetrying::class, fn () => $runs->update($run['public_id'], ['status' => 'cancelled']));
    $graph = StateGraph::make('executor')->node('work', function () use (&$calls) {
        $calls++;
        throw new RuntimeException('Transient error');
    })->retry('work', 3)->edge(StateGraph::START, 'work')->compile();

    expect(fn () => $executor->execute($graph, 'work', [], $run, $authority))->toThrow(RunStateChangedException::class)
        ->and($calls)->toBe(1);
});

it('checks ownership after waiting for the node concurrency lock', function () {
    [$executor, $runs, $run, $authority] = nodeExecutorFixture();
    $locks = new class($runs, $run) implements LockProvider
    {
        public function __construct(private InMemoryRunStore $runs, private array $run) {}

        public function withLock(string $key, Closure $callback): mixed
        {
            $this->runs->update($this->run['public_id'], ['status' => 'cancelled']);

            return $callback();
        }
    };
    $executor = nodeExecutorInstance($locks);
    $calls = 0;
    $graph = StateGraph::make('executor')->node('work', function () use (&$calls) {
        $calls++;

        return NodeResult::end();
    })->concurrency('work')->edge(StateGraph::START, 'work')->compile();

    expect(fn () => $executor->execute($graph, 'work', [], $run, $authority))->toThrow(RunStateChangedException::class)
        ->and($calls)->toBe(0);
});

it('shares one deadline across retries and caps retry backoff by that budget', function () {
    [$executor, , $run, $authority] = nodeExecutorFixture();
    $calls = 0;
    $graph = StateGraph::make('executor')->node('work', function () use (&$calls) {
        $calls++;
        throw new RuntimeException('Transient error');
    })->retry('work', 3, delayMs: 100)->timeout('work', 0.03)->edge(StateGraph::START, 'work')->compile();

    expect(fn () => $executor->execute($graph, 'work', [], $run, $authority))->toThrow(NodeTimeoutException::class)
        ->and($calls)->toBe(1)->and($authority->deadline())->toBeNull();
    // A timeout can still be recorded by the coordinator under the same owner.
    $authority->assertCurrent();
});

it('rejects new task side effects after the node deadline expires', function () {
    [$executor, , $run, $authority] = nodeExecutorFixture();
    $effects = 0;
    $graph = StateGraph::make('executor')->node('work', function (NodeContext $context) use (&$effects) {
        usleep(30000);
        $context->tasks()->once('after-deadline', [], function () use (&$effects) {
            $effects++;
        });

        return NodeResult::end();
    })->timeout('work', 0.01)->edge(StateGraph::START, 'work')->compile();

    expect(fn () => $executor->execute($graph, 'work', [], $run, $authority))->toThrow(NodeTimeoutException::class)
        ->and($effects)->toBe(0);
});

it('rejects late node outcomes and invalid return types without persisting a result', function () {
    [$executor, $runs, $run, $authority] = nodeExecutorFixture();
    $graph = StateGraph::make('executor')->node('work', function () use ($runs, $run) {
        $runs->update($run['public_id'], ['status' => 'cancelled']);

        return ['late' => true];
    })->edge(StateGraph::START, 'work')->compile();
    expect(fn () => $executor->execute($graph, 'work', [], $run, $authority))->toThrow(RunStateChangedException::class);

    [$executor, , $run, $authority] = nodeExecutorFixture();
    $invalid = StateGraph::make('executor')->node('work', fn () => 'invalid')->edge(StateGraph::START, 'work')->compile();
    expect(fn () => $executor->execute($invalid, 'work', [], $run, $authority))->toThrow(TypeError::class, 'NodeResult or an array');
});

function nodeExecutorFixture(): array
{
    $runs = new InMemoryRunStore;
    $run = $runs->create('executor', '1', 'executor-thread');

    return [nodeExecutorInstance(), $runs, $run, new ExecutionAuthority($runs, $run)];
}

function nodeExecutorInstance(?LockProvider $locks = null): NodeExecutor
{
    $locks ??= new class implements LockProvider
    {
        public function withLock(string $key, Closure $callback): mixed
        {
            return $callback();
        }
    };

    return new NodeExecutor(app(), new InMemoryMemoryStore, new InMemoryTraceStore, new InMemoryTaskStore, $locks, new RunEventDispatcher);
}
