<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\RunStore;

class InMemoryRunStore implements RunStore
{
    protected array $runs = [];

    public function create(string $graphKey, string $graphVersion, string $threadId, array $input = [], array $meta = []): array
    {
        $run = [
            'id' => count($this->runs) + 1,
            'public_id' => 'run_'.str()->ulid(),
            'thread_id' => $threadId,
            'graph_key' => $graphKey,
            'graph_version' => $graphVersion,
            'status' => 'running',
            'current_checkpoint_id' => null,
            'input' => $input,
            'error' => null,
            'meta' => $meta,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->runs[$run['public_id']] = $run;

        return $run;
    }

    public function find(string $runId): ?array
    {
        return $this->runs[$runId] ?? null;
    }

    public function list(array $filters = [], int $limit = 50): array
    {
        $runs = array_values(array_filter($this->runs, function (array $run) use ($filters): bool {
            foreach (['status', 'thread_id', 'graph_key', 'graph_version'] as $field) {
                if (isset($filters[$field]) && $run[$field] !== $filters[$field]) {
                    return false;
                }
            }

            return true;
        }));

        usort($runs, fn (array $a, array $b): int => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));

        return array_slice($runs, 0, max(1, min($limit, 500)));
    }

    public function update(string $runId, array $attributes): array
    {
        $this->runs[$runId] = array_merge($this->runs[$runId], $attributes, ['updated_at' => now()]);

        return $this->runs[$runId];
    }
}
