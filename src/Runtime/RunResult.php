<?php

namespace Heiner\AgentGraph\Runtime;

class RunResult
{
    public function __construct(
        protected array $run,
        protected array $state = [],
        protected ?array $interrupt = null,
        protected array $events = [],
    ) {}

    public function runId(): string
    {
        return $this->run['public_id'];
    }

    public function threadId(): string
    {
        return $this->run['thread_id'];
    }

    public function status(): string
    {
        return $this->run['status'];
    }

    public function completed(): bool
    {
        return $this->status() === 'completed';
    }

    public function interrupted(): bool
    {
        return $this->status() === 'interrupted';
    }

    public function failed(): bool
    {
        return $this->status() === 'failed';
    }

    public function cancelled(): bool
    {
        return $this->status() === 'cancelled';
    }

    public function error(): ?array
    {
        return $this->run['error'] ?? null;
    }

    public function meta(): array
    {
        return $this->run['meta'] ?? [];
    }

    public function resumeAt(): mixed
    {
        return $this->run['resume_at'] ?? null;
    }

    public function state(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->state;
        }

        return $this->state[$key] ?? $default;
    }

    public function interrupt(): ?array
    {
        return $this->interrupt;
    }

    public function events(): array
    {
        return $this->events;
    }

    public function withEvents(array $events): self
    {
        return new self($this->run, $this->state, $this->interrupt, $events);
    }
}
