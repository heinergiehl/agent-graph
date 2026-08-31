<?php

namespace Heiner\AgentGraph\Persistence;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Heiner\AgentGraph\Contracts\LeasingTaskStore;
use Heiner\AgentGraph\Exceptions\TaskClaimLostException;
use Heiner\AgentGraph\Persistence\Concerns\SerializesDatabaseValues;
use Heiner\AgentGraph\Persistence\Concerns\UsesAgentGraphDatabaseConnection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
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
        $claim = function () use ($key, $inputHash, $input, $context): array {
            $record = $this->query()->where('task_key', $key)->lockForUpdate()->first();
            $now = now();

            if ($record === null) {
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

                return $this->findByKey($key)
                    ?? throw new RuntimeException("Task key [{$key}] was not found after creation.");
            }

            $existing = $this->decodeRecord($record, ['input', 'result', 'error', 'meta']);

            if ($existing['input_hash'] !== $inputHash) {
                throw new RuntimeException("Task key [{$key}] was reused with different input.");
            }

            if ($existing['status'] === 'completed') {
                return $existing;
            }

            if ($this->activeLeaseUntil($existing) !== null) {
                throw new RuntimeException("Task key [{$key}] is already running.");
            }

            $attributes = [
                'status' => 'running',
                'attempts' => $existing['attempts'] + 1,
                'locked_until' => $this->leaseUntil(),
                'result' => null,
                'error' => null,
                'updated_at' => $now,
            ];

            foreach (['run_id', 'checkpoint_id', 'node_id', 'meta'] as $field) {
                if (array_key_exists($field, $context)) {
                    $attributes[$field] = $field === 'meta' ? $this->encode($context[$field]) : $context[$field];
                }
            }

            $this->query()->where('task_key', $key)->update($attributes);

            return $this->findByKey($key)
                ?? throw new RuntimeException("Task key [{$key}] was not found after claiming.");
        };

        try {
            return $this->connection()->transaction($claim, 3);
        } catch (UniqueConstraintViolationException) {
            // Retry after rollback (or savepoint rollback), never inside an
            // aborted PostgreSQL transaction after a concurrent first insert.
            return $this->connection()->transaction($claim, 3);
        }
    }

    public function complete(string $key, int $attempt, mixed $result): array
    {
        return $this->finishAttempt($key, $attempt, [
            'status' => 'completed',
            'result' => $this->encode($result),
            'error' => null,
        ]);
    }

    public function fail(string $key, int $attempt, string $message, array $meta = []): array
    {
        return $this->finishAttempt($key, $attempt, [
            'status' => 'failed',
            'error' => $this->encode(['message' => $message, 'meta' => $meta]),
        ]);
    }

    protected function table(): string
    {
        return config('agent-graph.tables.tasks', 'agent_graph_tasks');
    }

    protected function leaseUntil(): CarbonImmutable
    {
        return now()->addSeconds((int) config('agent-graph.tasks.lease_seconds', 300))->toImmutable();
    }

    protected function finishAttempt(string $key, int $attempt, array $attributes): array
    {
        return $this->connection()->transaction(function () use ($key, $attempt, $attributes): array {
            $updated = $this->query()
                ->where('task_key', $key)
                ->where('status', 'running')
                ->where('attempts', $attempt)
                ->update(array_merge($attributes, [
                    'locked_until' => null,
                    'updated_at' => now(),
                ]));

            if ($attempt < 1 || $updated !== 1) {
                throw new TaskClaimLostException("Task key [{$key}] attempt [{$attempt}] is no longer active.");
            }

            return $this->findByKey($key)
                ?? throw new RuntimeException("Task key [{$key}] was not found after finishing its attempt.");
        });
    }
}
