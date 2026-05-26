<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\RunStore;
use Heiner\AgentGraph\Persistence\Concerns\SerializesDatabaseValues;
use Illuminate\Database\DatabaseManager;

class DatabaseRunStore implements RunStore
{
    use SerializesDatabaseValues;

    public function __construct(protected DatabaseManager $db) {}

    public function create(string $graphKey, string $graphVersion, string $threadId, array $input = [], array $meta = []): array
    {
        $publicId = 'run_'.str()->ulid();
        $now = now();

        $this->db->table($this->table())->insert([
            'public_id' => $publicId,
            'thread_id' => $threadId,
            'graph_key' => $graphKey,
            'graph_version' => $graphVersion,
            'status' => 'running',
            'input' => $this->encode($input),
            'meta' => $this->encode($meta),
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($publicId);
    }

    public function find(string $runId): ?array
    {
        $record = $this->db->table($this->table())->where('public_id', $runId)->first();

        return $record ? $this->decodeRecord($record, ['input', 'error', 'meta']) : null;
    }

    public function list(array $filters = [], int $limit = 50): array
    {
        $query = $this->db->table($this->table());

        foreach (['status', 'thread_id', 'graph_key', 'graph_version'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 500)))
            ->get()
            ->map(fn ($record) => $this->decodeRecord($record, ['input', 'error', 'meta']))
            ->all();
    }

    public function listChildRuns(string $parentRunId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));

        return $this->db->table($this->table())
            ->orderByDesc('id')
            ->limit(1000)
            ->get()
            ->map(fn ($record) => $this->decodeRecord($record, ['input', 'error', 'meta']))
            ->filter(fn (array $run): bool => ($run['meta']['parent']['run_id'] ?? null) === $parentRunId)
            ->take($limit)
            ->values()
            ->all();
    }

    public function listTimeTravelChildren(string $checkpointId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));

        return $this->db->table($this->table())
            ->orderByDesc('id')
            ->limit(1000)
            ->get()
            ->map(fn ($record) => $this->decodeRecord($record, ['input', 'error', 'meta']))
            ->filter(fn (array $run): bool => ($run['meta']['time_travel']['source_checkpoint_id'] ?? null) === $checkpointId)
            ->take($limit)
            ->values()
            ->all();
    }

    public function update(string $runId, array $attributes): array
    {
        foreach (['input', 'error', 'meta'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = $this->encode($attributes[$field]);
            }
        }

        $attributes['updated_at'] = now();

        if (($attributes['status'] ?? null) === 'completed') {
            $attributes['finished_at'] ??= now();
        }

        if (($attributes['status'] ?? null) === 'failed') {
            $attributes['failed_at'] ??= now();
        }

        $this->db->table($this->table())->where('public_id', $runId)->update($attributes);

        return $this->find($runId);
    }

    protected function table(): string
    {
        return config('agent-graph.tables.runs', 'agent_graph_runs');
    }
}
