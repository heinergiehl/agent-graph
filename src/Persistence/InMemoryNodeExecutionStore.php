<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\NodeExecutionStore;

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
                'locked_until' => $lockedUntil,
                'started_at' => $execution['started_at'] ?? now(),
                'updated_at' => now(),
            ]);

            return $this->executions[$index];
        }

        return null;
    }

    public function complete(string $executionId, array $result): array
    {
        return $this->updateResult($executionId, 'completed', $result);
    }

    public function interrupt(string $executionId, array $result): array
    {
        return $this->updateResult($executionId, 'interrupted', $result);
    }

    public function fail(string $executionId, array $error): array
    {
        return $this->updateResult($executionId, 'failed', ['error' => $error]);
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

    protected function updateResult(string $executionId, string $status, array $result): array
    {
        foreach ($this->executions as $index => $execution) {
            if (($execution['execution_id'] ?? null) !== $executionId) {
                continue;
            }

            $this->executions[$index] = array_merge($execution, $result, [
                'status' => $status,
                'locked_until' => null,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->executions[$index];
        }

        throw new \RuntimeException("Node execution [{$executionId}] was not found.");
    }
}
