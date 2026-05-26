<?php

namespace Heiner\AgentGraph\Runtime;

class CheckpointSnapshot
{
    public function __construct(
        protected array $checkpoint,
        protected array $writes = [],
        protected ?array $previousCheckpoint = null,
    ) {}

    public function checkpoint(): array
    {
        return $this->checkpoint;
    }

    public function checkpointId(): string
    {
        return $this->checkpoint['checkpoint_id'];
    }

    public function runId(): string
    {
        return $this->checkpoint['run_id'];
    }

    public function threadId(): string
    {
        return $this->checkpoint['thread_id'];
    }

    public function graphKey(): string
    {
        return $this->checkpoint['graph_key'];
    }

    public function graphVersion(): string
    {
        return $this->checkpoint['graph_version'];
    }

    public function parentCheckpointId(): ?string
    {
        return $this->checkpoint['parent_checkpoint_id'] ?? null;
    }

    public function step(): int
    {
        return (int) $this->checkpoint['step'];
    }

    public function state(?string $key = null, mixed $default = null): mixed
    {
        $state = $this->checkpoint['state'] ?? [];

        if ($key === null) {
            return $state;
        }

        return $state[$key] ?? $default;
    }

    public function stateBefore(?string $key = null, mixed $default = null): mixed
    {
        $state = $this->previousCheckpoint['state'] ?? null;

        if ($state === null) {
            return $key === null ? null : $default;
        }

        if ($key === null) {
            return $state;
        }

        return $state[$key] ?? $default;
    }

    public function stateAfter(?string $key = null, mixed $default = null): mixed
    {
        return $this->state($key, $default);
    }

    public function nextNodes(): array
    {
        return $this->checkpoint['next_nodes'] ?? [];
    }

    public function completedNodes(): array
    {
        return $this->checkpoint['completed_nodes'] ?? [];
    }

    public function meta(): array
    {
        return $this->checkpoint['meta'] ?? [];
    }

    public function writes(): array
    {
        return $this->writes;
    }
}
