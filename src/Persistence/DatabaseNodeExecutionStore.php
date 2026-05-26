<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\NodeExecutionStore;
use Heiner\AgentGraph\Persistence\Concerns\SerializesDatabaseValues;
use Illuminate\Database\DatabaseManager;

class DatabaseNodeExecutionStore implements NodeExecutionStore
{
    use SerializesDatabaseValues;

    public function __construct(protected DatabaseManager $db) {}

    public function record(array $execution): array
    {
        $now = now();

        $this->db->table($this->table())->insert([
            'run_id' => $execution['run_id'],
            'step' => $execution['step'],
            'schedule_index' => $execution['schedule_index'] ?? 0,
            'node_id' => $execution['node_id'],
            'status' => $execution['status'],
            'writes' => $this->encode($execution['writes'] ?? []),
            'next_schedule' => $this->encode($execution['next_schedule'] ?? []),
            'interrupt' => $this->encode($execution['interrupt'] ?? null),
            'error' => $this->encode($execution['error'] ?? null),
            'meta' => $this->encode($execution['meta'] ?? []),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->listForRun((string) $execution['run_id'])[0] ?? [];
    }

    public function listForRun(string $runId): array
    {
        return $this->db->table($this->table())
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $record): array => $this->decodeRecord($record, ['writes', 'next_schedule', 'interrupt', 'error', 'meta']))
            ->all();
    }

    protected function table(): string
    {
        return config('agent-graph.tables.node_executions', 'agent_graph_node_executions');
    }
}
