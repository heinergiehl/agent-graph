<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\TraceStore;
use Heiner\AgentGraph\Persistence\Concerns\SerializesDatabaseValues;
use Heiner\AgentGraph\Tracing\RedactsTracePayloads;
use Illuminate\Database\DatabaseManager;

class DatabaseTraceStore implements TraceStore
{
    use RedactsTracePayloads;
    use SerializesDatabaseValues;

    public function __construct(protected DatabaseManager $db) {}

    public function record(string $runId, string $event, array $payload = [], array $meta = []): array
    {
        $id = $this->db->table($this->table())->insertGetId([
            'run_id' => $runId,
            'event' => $event,
            'payload' => $this->encode($this->redactPayload($payload)),
            'meta' => $this->encode($this->redactPayload($meta)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->decodeRecord($this->db->table($this->table())->where('id', $id)->first(), ['payload', 'meta']);
    }

    public function listForRun(string $runId): array
    {
        return $this->db->table($this->table())
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get()
            ->map(fn ($record) => $this->decodeRecord($record, ['payload', 'meta']))
            ->all();
    }

    protected function table(): string
    {
        return config('agent-graph.tables.traces', 'agent_graph_traces');
    }
}
