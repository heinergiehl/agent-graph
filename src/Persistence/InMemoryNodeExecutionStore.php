<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\NodeExecutionStore;

class InMemoryNodeExecutionStore implements NodeExecutionStore
{
    protected array $executions = [];

    public function record(array $execution): array
    {
        $execution = array_merge([
            'id' => count($this->executions) + 1,
            'schedule_index' => 0,
            'writes' => [],
            'next_schedule' => [],
            'interrupt' => null,
            'error' => null,
            'meta' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ], $execution);

        $this->executions[] = $execution;

        return $execution;
    }

    public function listForRun(string $runId): array
    {
        return array_values(array_filter($this->executions, fn (array $execution): bool => $execution['run_id'] === $runId));
    }
}
