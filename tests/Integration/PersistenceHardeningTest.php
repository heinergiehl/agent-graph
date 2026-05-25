<?php

use Heiner\AgentGraph\Contracts\DelayScheduler;
use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Contracts\WriteStore;
use Heiner\AgentGraph\Exceptions\SerializationException;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Persistence\DatabaseCheckpointStore;
use Heiner\AgentGraph\Persistence\DatabaseInterruptStore;
use Heiner\AgentGraph\Persistence\DatabaseMemoryStore;
use Heiner\AgentGraph\Persistence\DatabaseRunStore;
use Heiner\AgentGraph\Persistence\DatabaseTaskStore;
use Heiner\AgentGraph\Persistence\DatabaseTraceStore;
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
        interrupts: new DatabaseInterruptStore(app('db')),
        memory: new DatabaseMemoryStore(app('db')),
        traces: new DatabaseTraceStore(app('db')),
        locks: app(LockProvider::class),
        delayScheduler: app(DelayScheduler::class),
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
}

final class RollbackAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'persist me']);
    }
}
