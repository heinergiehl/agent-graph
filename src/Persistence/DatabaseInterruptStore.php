<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Persistence\Concerns\SerializesDatabaseValues;
use Heiner\AgentGraph\Persistence\Concerns\UsesAgentGraphDatabaseConnection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

class DatabaseInterruptStore implements InterruptStore
{
    use SerializesDatabaseValues;
    use UsesAgentGraphDatabaseConnection;

    public function __construct(protected DatabaseManager $db) {}

    public function create(array $interrupt): array
    {
        $interruptId = 'int_'.str()->ulid();
        $now = now();

        $this->query()->insert([
            'interrupt_id' => $interruptId,
            'run_id' => $interrupt['run_id'],
            'checkpoint_id' => $interrupt['checkpoint_id'],
            'node_id' => $interrupt['node_id'],
            'type' => $interrupt['type'],
            'status' => 'pending',
            'payload' => $this->encode($interrupt['payload'] ?? []),
            'expires_at' => $interrupt['expires_at'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($interruptId);
    }

    public function find(string $interruptId): ?array
    {
        $record = $this->query()->where('interrupt_id', $interruptId)->first();

        return $record ? $this->decodeRecord($record, ['payload', 'response']) : null;
    }

    public function listForRun(string $runId): array
    {
        return $this->query()
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get()
            ->map(fn ($record) => $this->decodeRecord($record, ['payload', 'response']))
            ->all();
    }

    public function pendingForRun(string $runId): ?array
    {
        $record = $this->query()
            ->where('run_id', $runId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();

        return $record ? $this->decodeRecord($record, ['payload', 'response']) : null;
    }

    public function resolve(string $interruptId, array $response, ?string $resolvedBy = null): array
    {
        $this->query()->where('interrupt_id', $interruptId)->update([
            'status' => 'resolved',
            'response' => $this->encode($response),
            'resolved_by' => $resolvedBy,
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->find($interruptId);
    }

    public function resolvePending(string $interruptId, string $runId, array $response, ?string $resolvedBy = null): array
    {
        $updated = $this->query()
            ->where('interrupt_id', $interruptId)
            ->where('run_id', $runId)
            ->where('status', 'pending')
            ->update([
                'status' => 'resolved',
                'response' => $this->encode($response),
                'resolved_by' => $resolvedBy,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated < 1) {
            throw new RuntimeException("Interrupt is no longer pending for run [{$runId}] and interrupt [{$interruptId}].");
        }

        return $this->find($interruptId)
            ?? throw new RuntimeException("Interrupt [{$interruptId}] was not found after resolving.");
    }

    public function expirePending(mixed $now = null): int
    {
        return $this->query()
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now ?? now())
            ->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);
    }

    protected function table(): string
    {
        return config('agent-graph.tables.interrupts', 'agent_graph_interrupts');
    }
}
