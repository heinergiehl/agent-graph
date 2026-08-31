<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\NodeExecutionStore;
use Heiner\AgentGraph\Exceptions\NodeExecutionClaimLostException;
use Heiner\AgentGraph\Persistence\Concerns\SerializesDatabaseValues;
use Heiner\AgentGraph\Persistence\Concerns\UsesAgentGraphDatabaseConnection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

class DatabaseNodeExecutionStore implements NodeExecutionStore
{
    use SerializesDatabaseValues;
    use UsesAgentGraphDatabaseConnection;

    public function __construct(protected DatabaseManager $db) {}

    public function schedule(array $execution): array
    {
        $execution['execution_id'] ??= 'nex_'.str()->ulid();
        $execution['status'] ??= 'pending';

        return $this->record($execution);
    }

    public function record(array $execution): array
    {
        $executionId = $execution['execution_id'] ?? 'nex_'.str()->ulid();
        $now = now();

        $this->query()->insert([
            'execution_id' => $executionId,
            'run_id' => $execution['run_id'],
            'checkpoint_id' => $execution['checkpoint_id'] ?? null,
            'step' => $execution['step'],
            'schedule_index' => $execution['schedule_index'] ?? 0,
            'node_id' => $execution['node_id'],
            'status' => $execution['status'],
            'base_state' => $this->encode($execution['base_state'] ?? []),
            'node_state' => $this->encode($execution['node_state'] ?? []),
            'resume_payload' => $this->encode($execution['resume_payload'] ?? null),
            'interrupt_id' => $execution['interrupt_id'] ?? null,
            'writes' => $this->encode($execution['writes'] ?? []),
            'next_schedule' => $this->encode($execution['next_schedule'] ?? []),
            'interrupt' => $this->encode($execution['interrupt'] ?? null),
            'error' => $this->encode($execution['error'] ?? null),
            'meta' => $this->encode($execution['meta'] ?? []),
            'claim_token' => $execution['claim_token'] ?? null,
            'locked_until' => $execution['locked_until'] ?? null,
            'started_at' => $execution['started_at'] ?? null,
            'finished_at' => $execution['finished_at'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $record = $this->find($executionId);

        if ($record === null) {
            throw new RuntimeException("Node execution [{$executionId}] could not be read after it was recorded.");
        }

        return $record;
    }

    public function find(string $executionId): ?array
    {
        $record = $this->query()->where('execution_id', $executionId)->first();

        return $record ? $this->decodeExecution($record) : null;
    }

    public function claim(string $executionId, mixed $lockedUntil): ?array
    {
        return $this->connection()->transaction(function () use ($executionId, $lockedUntil): ?array {
            $record = $this->query()->where('execution_id', $executionId)->lockForUpdate()->first();

            if ($record === null) {
                return null;
            }

            $execution = $this->decodeExecution($record);

            if (in_array($execution['status'], ['completed', 'interrupted', 'failed'], true)) {
                return $execution;
            }

            if ($execution['status'] === 'running'
                && $execution['locked_until'] !== null
                && now()->lessThan($execution['locked_until'])) {
                return null;
            }

            $this->query()->where('execution_id', $executionId)->update([
                'status' => 'running',
                'claim_token' => (string) str()->ulid(),
                'locked_until' => $lockedUntil,
                'started_at' => $execution['started_at'] ?? now(),
                'updated_at' => now(),
            ]);

            return $this->find($executionId);
        });
    }

    public function complete(string $executionId, string $claimToken, array $result): array
    {
        return $this->updateResult($executionId, $claimToken, 'completed', $result);
    }

    public function interrupt(string $executionId, string $claimToken, array $result): array
    {
        return $this->updateResult($executionId, $claimToken, 'interrupted', $result);
    }

    public function fail(string $executionId, string $claimToken, array $error): array
    {
        return $this->updateResult($executionId, $claimToken, 'failed', ['error' => $error]);
    }

    public function listForRun(string $runId): array
    {
        return $this->query()
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $record): array => $this->decodeExecution($record))
            ->all();
    }

    public function listForRunStep(string $runId, int $step): array
    {
        return $this->query()
            ->where('run_id', $runId)
            ->where('step', $step)
            ->orderBy('schedule_index')
            ->orderBy('id')
            ->get()
            ->map(fn (object $record): array => $this->decodeExecution($record))
            ->all();
    }

    protected function updateResult(string $executionId, string $claimToken, string $status, array $result): array
    {
        if ($claimToken === '') {
            throw new NodeExecutionClaimLostException("Claim for node execution [{$executionId}] is no longer active.");
        }

        return $this->connection()->transaction(function () use ($executionId, $claimToken, $status, $result): array {
            foreach (['base_state', 'node_state', 'resume_payload', 'writes', 'next_schedule', 'interrupt', 'error', 'meta'] as $field) {
                if (array_key_exists($field, $result)) {
                    $result[$field] = $this->encode($result[$field]);
                }
            }

            $result['status'] = $status;
            $result['claim_token'] = $claimToken;
            $result['locked_until'] = null;
            $result['finished_at'] = now();
            $result['updated_at'] = now();

            $updated = $this->query()
                ->where('execution_id', $executionId)
                ->where('status', 'running')
                ->where('claim_token', $claimToken)
                ->update($result);

            if ($updated !== 1) {
                throw new NodeExecutionClaimLostException("Claim for node execution [{$executionId}] is no longer active.");
            }

            $record = $this->find($executionId);

            if ($record === null) {
                throw new RuntimeException("Node execution [{$executionId}] could not be read after it was updated.");
            }

            return $record;
        });
    }

    protected function decodeExecution(object $record): array
    {
        return $this->decodeRecord($record, [
            'base_state',
            'node_state',
            'resume_payload',
            'writes',
            'next_schedule',
            'interrupt',
            'error',
            'meta',
        ]);
    }

    protected function table(): string
    {
        return config('agent-graph.tables.node_executions', 'agent_graph_node_executions');
    }
}
