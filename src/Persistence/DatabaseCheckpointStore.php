<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\CheckpointStore;
use Heiner\AgentGraph\Persistence\Concerns\SerializesDatabaseValues;
use Heiner\AgentGraph\Persistence\Concerns\UsesAgentGraphDatabaseConnection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

class DatabaseCheckpointStore implements CheckpointStore
{
    use SerializesDatabaseValues;
    use UsesAgentGraphDatabaseConnection;

    public function __construct(protected DatabaseManager $db) {}

    public function create(array $checkpoint): array
    {
        $checkpointId = 'chk_'.str()->ulid();
        $now = now();

        $this->query()->insert([
            'checkpoint_id' => $checkpointId,
            'parent_checkpoint_id' => $checkpoint['parent_checkpoint_id'] ?? null,
            'run_id' => $checkpoint['run_id'],
            'thread_id' => $checkpoint['thread_id'],
            'graph_key' => $checkpoint['graph_key'],
            'graph_version' => $checkpoint['graph_version'] ?? '1',
            'step' => $checkpoint['step'],
            'state' => $this->encode($checkpoint['state']),
            'next_nodes' => $this->encode($checkpoint['next_nodes'] ?? []),
            'completed_nodes' => $this->encode($checkpoint['completed_nodes'] ?? []),
            'interrupts' => $this->encode($checkpoint['interrupts'] ?? []),
            'meta' => $this->encode($checkpoint['meta'] ?? []),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->byCheckpointId($checkpointId);
    }

    public function find(string $checkpointId): ?array
    {
        $record = $this->query()->where('checkpoint_id', $checkpointId)->first();

        return $record ? $this->decodeRecord($record, ['state', 'next_nodes', 'completed_nodes', 'interrupts', 'meta']) : null;
    }

    public function latestForRun(string $runId): ?array
    {
        $record = $this->query()->where('run_id', $runId)->orderByDesc('step')->first();

        return $record ? $this->decodeRecord($record, ['state', 'next_nodes', 'completed_nodes', 'interrupts', 'meta']) : null;
    }

    public function listForRun(string $runId): array
    {
        return $this->query()
            ->where('run_id', $runId)
            ->orderBy('step')
            ->get()
            ->map(fn ($record) => $this->decodeRecord($record, ['state', 'next_nodes', 'completed_nodes', 'interrupts', 'meta']))
            ->all();
    }

    protected function byCheckpointId(string $checkpointId): array
    {
        return $this->find($checkpointId) ?? throw new RuntimeException("Checkpoint [{$checkpointId}] was not found after creation.");
    }

    protected function table(): string
    {
        return config('agent-graph.tables.checkpoints', 'agent_graph_checkpoints');
    }
}
