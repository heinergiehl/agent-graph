<?php

namespace Heiner\AgentGraph\Runtime;

use Heiner\AgentGraph\Contracts\MemoryStore;
use Heiner\AgentGraph\Contracts\TraceStore;

class NodeContext
{
    public function __construct(
        protected array $state,
        protected string $runId,
        protected string $threadId,
        protected string $nodeId,
        protected ?string $checkpointId,
        protected array $graphMeta,
        protected MemoryStore $memory,
        protected TraceStore $traces,
        protected TaskRunner $tasks,
        protected ?array $resumePayload = null,
        protected ?string $interruptId = null,
    ) {}

    public function state(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->state;
        }

        return $this->state[$key] ?? $default;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function threadId(): string
    {
        return $this->threadId;
    }

    public function nodeId(): string
    {
        return $this->nodeId;
    }

    public function checkpointId(): ?string
    {
        return $this->checkpointId;
    }

    public function graphMeta(): array
    {
        return $this->graphMeta;
    }

    public function memory(): MemoryStore
    {
        return $this->memory;
    }

    public function traces(): TraceStore
    {
        return $this->traces;
    }

    public function tasks(): TaskRunner
    {
        return $this->tasks;
    }

    public function resumePayload(?string $stateKey = null): ?array
    {
        if ($stateKey === null) {
            return $this->resumePayload;
        }

        $payload = $this->resumePayload[$stateKey] ?? $this->state[$stateKey] ?? null;

        return is_array($payload) ? $payload : null;
    }

    public function hasResumePayload(?string $stateKey = null): bool
    {
        if ($stateKey === null) {
            return $this->resumePayload !== null;
        }

        return is_array($this->resumePayload[$stateKey] ?? $this->state[$stateKey] ?? null);
    }

    public function interruptId(): ?string
    {
        return $this->interruptId;
    }
}
