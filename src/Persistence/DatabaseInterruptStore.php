<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Persistence\Concerns\SerializesDatabaseValues;
use Illuminate\Database\DatabaseManager;

class DatabaseInterruptStore implements InterruptStore
{
    use SerializesDatabaseValues;

    public function __construct(protected DatabaseManager $db) {}

    public function create(array $interrupt): array
    {
        $interruptId = 'int_'.str()->ulid();
        $now = now();

        $this->db->table($this->table())->insert([
            'interrupt_id' => $interruptId,
            'run_id' => $interrupt['run_id'],
            'checkpoint_id' => $interrupt['checkpoint_id'],
            'node_id' => $interrupt['node_id'],
            'type' => $interrupt['type'],
            'status' => 'pending',
            'payload' => $this->encode($interrupt['payload'] ?? []),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($interruptId);
    }

    public function find(string $interruptId): ?array
    {
        $record = $this->db->table($this->table())->where('interrupt_id', $interruptId)->first();

        return $record ? $this->decodeRecord($record, ['payload', 'response']) : null;
    }

    public function listForRun(string $runId): array
    {
        return $this->db->table($this->table())
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get()
            ->map(fn ($record) => $this->decodeRecord($record, ['payload', 'response']))
            ->all();
    }

    public function latestForRun(string $runId): ?array
    {
        $record = $this->db->table($this->table())
            ->where('run_id', $runId)
            ->orderByDesc('id')
            ->first();

        return $record ? $this->decodeRecord($record, ['payload', 'response']) : null;
    }

    public function pendingForRun(string $runId): ?array
    {
        $record = $this->db->table($this->table())
            ->where('run_id', $runId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();

        return $record ? $this->decodeRecord($record, ['payload', 'response']) : null;
    }

    public function resolve(string $interruptId, array $response, ?string $resolvedBy = null): array
    {
        $this->db->table($this->table())->where('interrupt_id', $interruptId)->update([
            'status' => 'resolved',
            'response' => $this->encode($response),
            'resolved_by' => $resolvedBy,
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->find($interruptId);
    }

    protected function table(): string
    {
        return config('agent-graph.tables.interrupts', 'agent_graph_interrupts');
    }
}
