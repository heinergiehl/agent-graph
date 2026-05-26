<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\TaskStore;

class InMemoryTaskStore implements TaskStore
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

    public function start(string $key, string $inputHash, array $input, array $context = []): array
    {
        $task = $this->tasks[$key] ?? [
            'id' => count($this->tasks) + 1,
            'task_key' => $key,
            'status' => 'running',
            'input_hash' => $inputHash,
            'input' => $input,
            'result' => null,
            'error' => null,
            'attempts' => 0,
            'run_id' => null,
            'checkpoint_id' => null,
            'node_id' => null,
            'meta' => [],
            'created_at' => now(),
        ];

        $task['status'] = 'running';
        $task['attempts']++;
        $task['updated_at'] = now();
        $task = array_merge($task, $context);

        $this->tasks[$key] = $task;

        return $task;
    }

    public function complete(string $key, mixed $result): array
    {
        $this->tasks[$key]['status'] = 'completed';
        $this->tasks[$key]['result'] = $result;
        $this->tasks[$key]['updated_at'] = now();

        return $this->tasks[$key];
    }

    public function fail(string $key, string $message, array $meta = []): array
    {
        $this->tasks[$key]['status'] = 'failed';
        $this->tasks[$key]['error'] = ['message' => $message, 'meta' => $meta];
        $this->tasks[$key]['updated_at'] = now();

        return $this->tasks[$key];
    }
}
