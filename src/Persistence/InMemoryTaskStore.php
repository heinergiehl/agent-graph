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
