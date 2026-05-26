<?php

namespace Heiner\AgentGraph\Runtime;

class RunTimelineStep
{
    public function __construct(
        protected int $step,
        protected ?string $nodeId,
        protected array $nodeIds,
        protected string $status,
        protected ?string $checkpointId,
        protected ?string $previousCheckpointId,
        protected array $writes = [],
        protected ?array $interrupt = null,
        protected ?array $error = null,
        protected array $meta = [],
        protected ?array $stateBefore = null,
        protected ?array $stateAfter = null,
        protected ?StateDiff $stateDiff = null,
    ) {}

    public function step(): int
    {
        return $this->step;
    }

    public function nodeId(): ?string
    {
        return $this->nodeId;
    }

    public function nodeIds(): array
    {
        return $this->nodeIds;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function checkpointId(): ?string
    {
        return $this->checkpointId;
    }

    public function previousCheckpointId(): ?string
    {
        return $this->previousCheckpointId;
    }

    public function writes(): array
    {
        return $this->writes;
    }

    public function interrupt(): ?array
    {
        return $this->interrupt;
    }

    public function error(): ?array
    {
        return $this->error;
    }

    public function meta(): array
    {
        return $this->meta;
    }

    public function stateBefore(): ?array
    {
        return $this->stateBefore;
    }

    public function stateAfter(): ?array
    {
        return $this->stateAfter;
    }

    public function stateDiff(): ?StateDiff
    {
        return $this->stateDiff;
    }

    public function toArray(): array
    {
        $payload = [
            'step' => $this->step,
            'node_id' => $this->nodeId,
            'completed_nodes' => $this->nodeIds,
            'status' => $this->status,
            'checkpoint_id' => $this->checkpointId,
            'previous_checkpoint_id' => $this->previousCheckpointId,
            'writes' => $this->writes,
            'interrupt' => $this->interrupt,
            'error' => $this->error,
            'meta' => $this->meta,
        ];

        if ($this->stateBefore !== null) {
            $payload['state_before'] = $this->stateBefore;
        }

        if ($this->stateAfter !== null) {
            $payload['state_after'] = $this->stateAfter;
        }

        if ($this->stateDiff !== null) {
            $payload['state_diff'] = $this->stateDiff->toArray();
        }

        return $payload;
    }
}
