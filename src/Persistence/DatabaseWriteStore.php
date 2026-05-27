<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\WriteStore;
use Heiner\AgentGraph\Persistence\Concerns\SerializesDatabaseValues;
use Heiner\AgentGraph\Persistence\Concerns\UsesAgentGraphDatabaseConnection;
use Illuminate\Database\DatabaseManager;

class DatabaseWriteStore implements WriteStore
{
    use SerializesDatabaseValues;
    use UsesAgentGraphDatabaseConnection;

    public function __construct(protected DatabaseManager $db) {}

    public function createMany(string $runId, string $checkpointId, string $nodeId, array $writes, array $meta = []): void
    {
        $now = now();

        foreach ($writes as $channel => $value) {
            $this->query()->insert([
                'checkpoint_id' => $checkpointId,
                'run_id' => $runId,
                'node_id' => $nodeId,
                'channel' => $channel,
                'key' => $channel,
                'value' => $this->encode($value),
                'reducer' => null,
                'meta' => $this->encode($meta),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function listForCheckpoint(string $checkpointId): array
    {
        return $this->query()
            ->where('checkpoint_id', $checkpointId)
            ->orderBy('id')
            ->get()
            ->map(fn ($record) => $this->decodeRecord($record, ['value', 'meta']))
            ->all();
    }

    public function listForRun(string $runId): array
    {
        return $this->query()
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get()
            ->map(fn ($record) => $this->decodeRecord($record, ['value', 'meta']))
            ->all();
    }

    protected function table(): string
    {
        return config('agent-graph.tables.writes', 'agent_graph_writes');
    }
}
