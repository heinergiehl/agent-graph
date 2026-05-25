<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\TaskStore;
use Heiner\AgentGraph\Persistence\Concerns\SerializesDatabaseValues;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

class DatabaseTaskStore implements TaskStore
{
    use SerializesDatabaseValues;

    public function __construct(protected DatabaseManager $db) {}

    public function findByKey(string $key): ?array
    {
        $record = $this->db->table($this->table())->where('task_key', $key)->first();

        return $record ? $this->decodeRecord($record, ['input', 'result', 'error', 'meta']) : null;
    }

    public function start(string $key, string $inputHash, array $input, array $context = []): array
    {
        $existing = $this->findByKey($key);
        $now = now();

        if ($existing === null) {
            try {
                $this->db->table($this->table())->insert([
                    'task_key' => $key,
                    'status' => 'running',
                    'input_hash' => $inputHash,
                    'input' => $this->encode($input),
                    'attempts' => 1,
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

        $this->db->table($this->table())->where('task_key', $key)->update([
            'status' => 'running',
            'attempts' => $existing['attempts'] + 1,
            'updated_at' => $now,
        ]);

        return $this->findByKey($key);
    }

    public function complete(string $key, mixed $result): array
    {
        $this->db->table($this->table())->where('task_key', $key)->update([
            'status' => 'completed',
            'result' => $this->encode($result),
            'updated_at' => now(),
        ]);

        return $this->findByKey($key);
    }

    public function fail(string $key, string $message, array $meta = []): array
    {
        $this->db->table($this->table())->where('task_key', $key)->update([
            'status' => 'failed',
            'error' => $this->encode(['message' => $message, 'meta' => $meta]),
            'updated_at' => now(),
        ]);

        return $this->findByKey($key);
    }

    protected function table(): string
    {
        return config('agent-graph.tables.tasks', 'agent_graph_tasks');
    }

    protected function isDuplicateKeyException(QueryException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'unique')
            || str_contains(strtolower($exception->getMessage()), 'duplicate');
    }
}
