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
        protected array $resumePayload = [],
        protected ?array $pendingInterrupt = null,
        protected ?array $resolvedInterrupt = null,
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

    public function hasResumePayload(?string $key = null): bool
    {
        if ($key === null) {
            return $this->resumePayload !== [];
        }

        return array_key_exists($key, $this->resumePayload);
    }

    public function resumePayload(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->resumePayload;
        }

        return $this->resumePayload[$key] ?? $default;
    }

    public function pendingInterrupt(): ?array
    {
        return $this->pendingInterrupt;
    }

    public function resolvedInterrupt(): ?array
    {
        return $this->resolvedInterrupt;
    }

    public function resolvedInterruptResponse(?string $key = null, mixed $default = null): mixed
    {
        $response = $this->resolvedInterrupt['response'] ?? null;

        if (! is_array($response)) {
            return $key === null ? null : $default;
        }

        if ($key === null) {
            return $response;
        }

        return $response[$key] ?? $default;
    }
}
