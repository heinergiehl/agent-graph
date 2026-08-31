<?php

namespace Heiner\AgentGraph\Persistence;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Heiner\AgentGraph\Contracts\LeasingTaskStore;
use Heiner\AgentGraph\Exceptions\TaskClaimLostException;
use RuntimeException;

class InMemoryTaskStore implements LeasingTaskStore
{
    protected array $tasks = [];

    public function findByKey(string $key): ?array
    {
        return $this->tasks[$key] ?? null;
    }

    public function list(array $filters = [], int $limit = 50): array
    {
        if ($limit <= 0) {
            return [];
        }

        $tasks = array_values($this->tasks);

        $tasks = array_filter($tasks, function (array $task) use ($filters): bool {
            foreach (['run_id', 'checkpoint_id', 'node_id', 'status'] as $filter) {
                if (isset($filters[$filter]) && $filters[$filter] !== '' && ($task[$filter] ?? null) !== $filters[$filter]) {
                    return false;
                }
            }

            return true;
        });

        usort($tasks, fn (array $a, array $b): int => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));

        return array_slice($tasks, 0, $limit);
    }

    public function activeLeaseUntil(array $task): ?DateTimeInterface
    {
        if (($task['status'] ?? null) !== 'running' || empty($task['locked_until'])) {
            return null;
        }

        $lockedUntil = $task['locked_until'] instanceof DateTimeInterface
            ? CarbonImmutable::instance($task['locked_until'])
            : CarbonImmutable::parse($task['locked_until']);

        return $lockedUntil->isFuture() ? $lockedUntil : null;
    }

    public function start(string $key, string $inputHash, array $input, array $context = []): array
    {
        $existing = $this->tasks[$key] ?? null;

        if ($existing !== null) {
            if ($existing['input_hash'] !== $inputHash) {
                throw new RuntimeException("Task key [{$key}] was reused with different input.");
            }

            if ($existing['status'] === 'completed') {
                return $existing;
            }

            if ($this->activeLeaseUntil($existing) !== null) {
                throw new RuntimeException("Task key [{$key}] is already running.");
            }
        }

        $task = $existing ?? [
            'id' => count($this->tasks) + 1,
            'task_key' => $key,
            'status' => 'running',
            'input_hash' => $inputHash,
            'input' => $input,
            'result' => null,
            'error' => null,
            'attempts' => 0,
            'locked_until' => null,
            'run_id' => null,
            'checkpoint_id' => null,
            'node_id' => null,
            'meta' => [],
            'created_at' => now(),
        ];

        $task['status'] = 'running';
        $task['attempts']++;
        $task['locked_until'] = now()->addSeconds((int) config('agent-graph.tasks.lease_seconds', 300));
        $task['result'] = null;
        $task['error'] = null;
        $task['updated_at'] = now();

        foreach (['run_id', 'checkpoint_id', 'node_id', 'meta'] as $field) {
            if (array_key_exists($field, $context)) {
                $task[$field] = $context[$field];
            }
        }

        $this->tasks[$key] = $task;

        return $task;
    }

    public function complete(string $key, int $attempt, mixed $result): array
    {
        $this->assertOwnedAttempt($key, $attempt);
        $this->tasks[$key]['status'] = 'completed';
        $this->tasks[$key]['result'] = $result;
        $this->tasks[$key]['error'] = null;
        $this->tasks[$key]['locked_until'] = null;
        $this->tasks[$key]['updated_at'] = now();

        return $this->tasks[$key];
    }

    public function fail(string $key, int $attempt, string $message, array $meta = []): array
    {
        $this->assertOwnedAttempt($key, $attempt);
        $this->tasks[$key]['status'] = 'failed';
        $this->tasks[$key]['error'] = ['message' => $message, 'meta' => $meta];
        $this->tasks[$key]['locked_until'] = null;
        $this->tasks[$key]['updated_at'] = now();

        return $this->tasks[$key];
    }

    protected function assertOwnedAttempt(string $key, int $attempt): void
    {
        $task = $this->tasks[$key] ?? null;

        if ($attempt < 1 || $task === null || $task['status'] !== 'running' || $task['attempts'] !== $attempt) {
            throw new TaskClaimLostException("Task key [{$key}] attempt [{$attempt}] is no longer active.");
        }
    }
}
