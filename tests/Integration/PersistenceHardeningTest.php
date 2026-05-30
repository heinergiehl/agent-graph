<?php

use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Contracts\NodeExecutionStore;
use Heiner\AgentGraph\Contracts\WriteStore;
use Heiner\AgentGraph\Exceptions\SerializationException;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Persistence\DatabaseCheckpointStore;
use Heiner\AgentGraph\Persistence\DatabaseInterruptStore;
use Heiner\AgentGraph\Persistence\DatabaseMemoryStore;
use Heiner\AgentGraph\Persistence\DatabaseNodeExecutionStore;
use Heiner\AgentGraph\Persistence\DatabaseRunStore;
use Heiner\AgentGraph\Persistence\DatabaseTaskStore;
use Heiner\AgentGraph\Persistence\DatabaseTraceStore;
use Heiner\AgentGraph\Persistence\InMemoryInterruptStore;
use Heiner\AgentGraph\Persistence\InMemoryNodeExecutionStore;
use Heiner\AgentGraph\Runtime\GraphRuntime;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\TaskRunner;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
});

it('raises clear serialization exceptions for non json payloads', function () {
    $runs = new DatabaseRunStore(app('db'));
    $resource = fopen('php://temp', 'r');

    try {
        expect(fn () => $runs->create('bad_payload', '1', 'thread', ['resource' => $resource]))
            ->toThrow(SerializationException::class, 'not JSON serializable');
    } finally {
        fclose($resource);
    }
});

it('rejects idempotent task key reuse with a different payload', function () {
    $tasks = new DatabaseTaskStore(app('db'));
    $runner = new TaskRunner($tasks, 'run_1', 'node_1');

    expect($runner->once('same-task', ['id' => 1], fn () => 'first'))->toBe('first');
    expect(fn () => $runner->once('same-task', ['id' => 2], fn () => 'second'))
        ->toThrow(RuntimeException::class, 'different input');
});

it('rolls back checkpoint creation when write persistence fails', function () {
    $runtime = new GraphRuntime(
        container: app(),
        runs: $runs = new DatabaseRunStore(app('db')),
        checkpoints: $checkpoints = new DatabaseCheckpointStore(app('db')),
        writes: new FailingWriteStore,
        tasks: new DatabaseTaskStore(app('db')),
        interrupts: new DatabaseInterruptStore(app('db')),
        memory: new DatabaseMemoryStore(app('db')),
        traces: new DatabaseTraceStore(app('db')),
        locks: app(LockProvider::class),
    );

    $graph = StateGraph::make('rollback_graph')
        ->state(['answer' => 'string|null'])
        ->node('answer', RollbackAnswerNode::class)
        ->edge(StateGraph::START, 'answer')
        ->edge('answer', StateGraph::END)
        ->compile();

    $result = $runtime->run($graph, 'rollback-thread');

    expect($result->failed())->toBeTrue()
        ->and($result->error()['message'])->toContain('write failed')
        ->and($checkpoints->listForRun($result->runId()))->toBeEmpty()
        ->and($runs->find($result->runId())['status'])->toBe('failed');
});

it('raises when database node execution updates target a missing execution', function (string $method, array $payload) {
    $store = new DatabaseNodeExecutionStore(app('db'));

    expect(fn () => $store->{$method}('missing-execution', $payload))
        ->toThrow(RuntimeException::class, 'Node execution [missing-execution] was not found.');
})->with([
    ['complete', ['writes' => []]],
    ['interrupt', ['interrupt' => ['type' => 'input', 'payload' => []]]],
    ['fail', ['message' => 'failed']],
]);

it('raises when a recorded database node execution cannot be read back by execution id', function () {
    $runs = new DatabaseRunStore(app('db'));
    $run = $runs->create('record_missing_readback', '1', 'thread-readback');
    $store = new MissingAfterInsertNodeExecutionStore(app('db'));

    expect(fn () => $store->record([
        'execution_id' => 'nex_missing_readback',
        'run_id' => $run['public_id'],
        'step' => 1,
        'node_id' => 'answer',
        'status' => 'pending',
    ]))->toThrow(RuntimeException::class, 'Node execution [nex_missing_readback] could not be read after it was recorded.');
});

it('rejects a second pending database interrupt for the same run', function () {
    $runs = new DatabaseRunStore(app('db'));
    $interrupts = new DatabaseInterruptStore(app('db'));
    $run = $runs->create('pending_interrupt_invariant', '1', 'thread-pending-interrupt');

    $first = $interrupts->create([
        'run_id' => $run['public_id'],
        'checkpoint_id' => 'chk_pending_one',
        'node_id' => 'review',
        'type' => 'input',
        'payload' => ['prompt' => 'First'],
    ]);

    expect(fn () => $interrupts->create([
        'run_id' => $run['public_id'],
        'checkpoint_id' => 'chk_pending_two',
        'node_id' => 'review',
        'type' => 'input',
        'payload' => ['prompt' => 'Second'],
    ]))->toThrow(RuntimeException::class, "Run [{$run['public_id']}] already has a pending interrupt.");

    $interrupts->resolvePending($first['interrupt_id'], $run['public_id'], ['answer' => 'done']);

    expect($interrupts->create([
        'run_id' => $run['public_id'],
        'checkpoint_id' => 'chk_pending_three',
        'node_id' => 'review',
        'type' => 'input',
        'payload' => ['prompt' => 'Third'],
    ])['status'])->toBe('pending');
});

it('rejects a second pending in-memory interrupt for the same run', function () {
    $interrupts = new InMemoryInterruptStore;

    $first = $interrupts->create([
        'run_id' => 'run_memory_pending',
        'checkpoint_id' => 'chk_pending_one',
        'node_id' => 'review',
        'type' => 'input',
        'payload' => ['prompt' => 'First'],
    ]);

    expect(fn () => $interrupts->create([
        'run_id' => 'run_memory_pending',
        'checkpoint_id' => 'chk_pending_two',
        'node_id' => 'review',
        'type' => 'input',
        'payload' => ['prompt' => 'Second'],
    ]))->toThrow(RuntimeException::class, 'Run [run_memory_pending] already has a pending interrupt.');

    $interrupts->resolvePending($first['interrupt_id'], 'run_memory_pending', ['answer' => 'done']);

    expect($interrupts->create([
        'run_id' => 'run_memory_pending',
        'checkpoint_id' => 'chk_pending_three',
        'node_id' => 'review',
        'type' => 'input',
        'payload' => ['prompt' => 'Third'],
    ])['status'])->toBe('pending');
});

it('returns terminal database node executions from claim without relocking', function (string $status) {
    $store = new DatabaseNodeExecutionStore(app('db'));

    $terminal = makeTerminalNodeExecution($store, $status);
    $claimed = $store->claim($terminal['execution_id'], now()->addMinute());

    expect($claimed['status'])->toBe($status)
        ->and($claimed['locked_until'])->toBeNull()
        ->and($claimed['finished_at'])->not->toBeNull()
        ->and($claimed['writes'])->toBe($terminal['writes'])
        ->and($claimed['next_schedule'])->toBe($terminal['next_schedule'])
        ->and($claimed['interrupt'])->toBe($terminal['interrupt'])
        ->and($claimed['error'])->toBe($terminal['error']);
})->with(['completed', 'interrupted', 'failed']);

it('returns terminal in-memory node executions from claim without relocking', function (string $status) {
    $store = new InMemoryNodeExecutionStore;

    $terminal = makeTerminalNodeExecution($store, $status);
    $claimed = $store->claim($terminal['execution_id'], now()->addMinute());

    expect($claimed['status'])->toBe($status)
        ->and($claimed['locked_until'])->toBeNull()
        ->and($claimed['finished_at'])->not->toBeNull()
        ->and($claimed['writes'])->toBe($terminal['writes'])
        ->and($claimed['next_schedule'])->toBe($terminal['next_schedule'])
        ->and($claimed['interrupt'])->toBe($terminal['interrupt'])
        ->and($claimed['error'])->toBe($terminal['error']);
})->with(['completed', 'interrupted', 'failed']);

final class FailingWriteStore implements WriteStore
{
    public function createMany(string $runId, string $checkpointId, string $nodeId, array $writes, array $meta = []): void
    {
        throw new RuntimeException('write failed');
    }

    public function listForRun(string $runId): array
    {
        return [];
    }

    public function listForCheckpoint(string $checkpointId): array
    {
        return [];
    }
}

final class RollbackAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'persist me']);
    }
}

final class MissingAfterInsertNodeExecutionStore extends DatabaseNodeExecutionStore
{
    public function find(string $executionId): ?array
    {
        return null;
    }
}

function makeTerminalNodeExecution(NodeExecutionStore $store, string $status): array
{
    $execution = $store->schedule([
        'run_id' => 'run_claim_'.$status,
        'checkpoint_id' => 'chk_claim_'.$status,
        'step' => 1,
        'schedule_index' => 0,
        'node_id' => 'worker',
        'base_state' => ['items' => []],
        'node_state' => ['items' => []],
    ]);

    return match ($status) {
        'completed' => $store->complete($execution['execution_id'], [
            'writes' => ['items' => ['done']],
            'next_schedule' => [['node' => 'next', 'input' => [], 'meta' => []]],
            'meta' => ['attempt' => 1],
        ]),
        'interrupted' => $store->interrupt($execution['execution_id'], [
            'writes' => ['items' => ['wait']],
            'interrupt' => ['type' => 'input', 'payload' => ['prompt' => 'Continue?']],
            'meta' => ['attempt' => 1],
        ]),
        'failed' => $store->fail($execution['execution_id'], ['message' => 'boom']),
        default => throw new InvalidArgumentException("Unsupported status [{$status}]."),
    };
}
