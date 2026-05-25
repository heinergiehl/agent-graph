<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\CheckpointStore;

class InMemoryCheckpointStore implements CheckpointStore
{
    protected array $checkpoints = [];

    public function create(array $checkpoint): array
    {
        $checkpoint = array_merge([
            'id' => count($this->checkpoints) + 1,
            'checkpoint_id' => 'chk_'.str()->ulid(),
            'parent_checkpoint_id' => null,
            'completed_nodes' => [],
            'interrupts' => [],
            'meta' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ], $checkpoint);

        $this->checkpoints[$checkpoint['checkpoint_id']] = $checkpoint;

        return $checkpoint;
    }

    public function latestForRun(string $runId): ?array
    {
        $items = $this->listForRun($runId);

        return $items === [] ? null : end($items);
    }

    public function listForRun(string $runId): array
    {
        $items = array_values(array_filter($this->checkpoints, fn (array $checkpoint): bool => $checkpoint['run_id'] === $runId));
        usort($items, fn (array $a, array $b): int => $a['step'] <=> $b['step']);

        return $items;
    }
}
