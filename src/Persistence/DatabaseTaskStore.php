<?php

namespace Heiner\AgentGraph\Persistence;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Heiner\AgentGraph\Contracts\LeasingTaskStore;
use Heiner\AgentGraph\Persistence\Concerns\SerializesDatabaseValues;
use Heiner\AgentGraph\Persistence\Concerns\UsesAgentGraphDatabaseConnection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

class DatabaseTaskStore implements LeasingTaskStore
{
    use SerializesDatabaseValues;
    use UsesAgentGraphDatabaseConnection;

    public function __construct(protected DatabaseManager $db) {}

    public function findByKey(string $key): ?array
    {
        $record = $this->query()->where('task_key', $key)->first();

        return $record ? $this->decodeRecord($record, ['input', 'result', 'error', 'meta']) : null;
    }

    public function activeLeaseUntil(array $task): ?DateTimeInterface
    {
        if (($task['status'] ?? null) !== 'running' || empty($task['locked_until'])) {
            return null;
        }

        $lockedUntil = CarbonImmutable::parse($task['locked_until']);

        return $lockedUntil->isFuture() ? $lockedUntil : null;
    }

    public function list(array $filters = [], int $limit = 50): array
    {
        if ($limit <= 0) {
            return [];
        }

        $query = $this->query();

        foreach (['run_id', 'checkpoint_id', 'node_id', 'status'] as $filter) {
            if (isset($filters[$filter]) && $filters[$filter] !== '') {
                $query->where($filter, $filters[$filter]);
            }
        }

        return $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (object $record): array => $this->decodeRecord($record, ['input', 'result', 'error', 'meta']))
            ->all();
    }

    public function start(string $key, string $inputHash, array $input, array $context = []): array
    {
        $existing = $this->findByKey($key);
        $now = now();

        if ($existing === null) {
            try {
                $this->query()->insert([
                    'task_key' => $key,
                    'status' => 'running',
                    'input_hash' => $inputHash,
                    'input' => $this->encode($input),
                    'attempts' => 1,
                    'locked_until' => $this->leaseUntil(),
                    'run_id' => $context['run_id'] ?? null,
                    'checkpoint_id' => $context['checkpoint_id'] ?? null,
                    'node_id' => $context['node_id'] ?? null,
                    'meta' => $this->encode($context['meta'] ?? []),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $this->findByKey($key);
            } catch (QueryException $exception) {
                if (! $this->isDuplicateKeyException($exception)) {
                    throw $exception;
                }

                $existing = $this->findByKey($key);
            }
        }

        if ($existing['input_hash'] !== $inputHash) {
            throw new RuntimeException("Task key [{$key}] was reused with different input.");
        }

        $this->query()->where('task_key', $key)->update([
            'status' => 'running',
            'attempts' => $existing['attempts'] + 1,
            'locked_until' => $this->leaseUntil(),
            'updated_at' => $now,
        ]);

        return $this->findByKey($key);
    }

    public function complete(string $key, mixed $result): array
    {
        $this->query()->where('task_key', $key)->update([
            'status' => 'completed',
            'result' => $this->encode($result),
            'locked_until' => null,
            'updated_at' => now(),
        ]);

        return $this->findByKey($key);
    }

    public function fail(string $key, string $message, array $meta = []): array
    {
        $this->query()->where('task_key', $key)->update([
            'status' => 'failed',
            'error' => $this->encode(['message' => $message, 'meta' => $meta]),
            'locked_until' => null,
            'updated_at' => now(),
        ]);

        return $this->findByKey($key);
    }

    protected function table(): string
    {
        return config('agent-graph.tables.tasks', 'agent_graph_tasks');
    }

    protected function leaseUntil(): CarbonImmutable
    {
        return now()->addSeconds((int) config('agent-graph.tasks.lease_seconds', 300))->toImmutable();
    }

    protected function isDuplicateKeyException(QueryException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'unique')
            || str_contains(strtolower($exception->getMessage()), 'duplicate');
    }
}
