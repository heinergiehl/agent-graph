<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\NodeExecutionStore;
use Heiner\AgentGraph\Exceptions\NodeExecutionClaimLostException;

class InMemoryNodeExecutionStore implements NodeExecutionStore
{
    protected array $executions = [];

    public function schedule(array $execution): array
    {
        $execution['execution_id'] ??= 'nex_'.str()->ulid();
        $execution['status'] ??= 'pending';

        return $this->record($execution);
    }

    public function record(array $execution): array
    {
        $execution = array_merge([
            'id' => count($this->executions) + 1,
            'execution_id' => 'nex_'.str()->ulid(),
            'checkpoint_id' => null,
            'schedule_index' => 0,
            'base_state' => [],
            'node_state' => [],
            'resume_payload' => null,
            'interrupt_id' => null,
            'writes' => [],
            'next_schedule' => [],
            'interrupt' => null,
            'error' => null,
            'meta' => [],
            'claim_token' => null,
            'locked_until' => null,
            'started_at' => null,
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $execution);

        $this->executions[] = $execution;

        return $execution;
    }

    public function find(string $executionId): ?array
    {
        foreach ($this->executions as $execution) {
            if (($execution['execution_id'] ?? null) === $executionId) {
                return $execution;
            }
        }

        return null;
    }

    public function claim(string $executionId, mixed $lockedUntil): ?array
    {
        foreach ($this->executions as $index => $execution) {
            if (($execution['execution_id'] ?? null) !== $executionId) {
                continue;
            }

            if (in_array($execution['status'], ['completed', 'interrupted', 'failed'], true)) {
                return $execution;
            }

            if (($execution['status'] ?? null) === 'running'
                && ($execution['locked_until'] ?? null) !== null
                && now()->lessThan($execution['locked_until'])) {
                return null;
            }

            $this->executions[$index] = array_merge($execution, [
                'status' => 'running',
                'claim_token' => (string) str()->ulid(),
                'locked_until' => $lockedUntil,
                'started_at' => $execution['started_at'] ?? now(),
                'updated_at' => now(),
            ]);

            return $this->executions[$index];
        }

        return null;
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
        return array_values(array_filter($this->executions, fn (array $execution): bool => $execution['run_id'] === $runId));
    }

    public function listForRunStep(string $runId, int $step): array
    {
        return array_values(array_filter(
            $this->executions,
            fn (array $execution): bool => $execution['run_id'] === $runId && (int) $execution['step'] === $step,
        ));
    }

    protected function updateResult(string $executionId, string $claimToken, string $status, array $result): array
    {
        foreach ($this->executions as $index => $execution) {
            if (($execution['execution_id'] ?? null) !== $executionId) {
                continue;
            }

            if ($claimToken === '' || $execution['status'] !== 'running' || $execution['claim_token'] !== $claimToken) {
                throw new NodeExecutionClaimLostException("Claim for node execution [{$executionId}] is no longer active.");
            }

            $this->executions[$index] = array_merge($execution, $result, [
                'status' => $status,
                'claim_token' => $claimToken,
                'locked_until' => null,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->executions[$index];
        }

        throw new NodeExecutionClaimLostException("Claim for node execution [{$executionId}] is no longer active.");
    }
}
