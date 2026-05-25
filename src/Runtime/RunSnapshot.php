<?php

namespace Heiner\AgentGraph\Runtime;

class RunSnapshot
{
    public function __construct(
        protected array $run,
        protected ?array $checkpoint = null,
        protected array $checkpoints = [],
        protected array $writes = [],
        protected ?array $interrupt = null,
        protected array $traces = [],
    ) {}

    public function run(): array
    {
        return $this->run;
    }

    public function runId(): string
    {
        return $this->run['public_id'];
    }

    public function threadId(): string
    {
        return $this->run['thread_id'];
    }

    public function graphKey(): string
    {
        return $this->run['graph_key'];
    }

    public function graphVersion(): string
    {
        return $this->run['graph_version'];
    }

    public function status(): string
    {
        return $this->run['status'];
    }

    public function state(?string $key = null, mixed $default = null): mixed
    {
        $state = $this->checkpoint['state'] ?? $this->run['input'] ?? [];

        if ($key === null) {
            return $state;
        }

        return $state[$key] ?? $default;
    }

    public function checkpoint(): ?array
    {
        return $this->checkpoint;
    }

    public function checkpoints(): array
    {
        return $this->checkpoints;
    }

    public function writes(): array
    {
        return $this->writes;
    }

    public function interrupt(): ?array
    {
        return $this->interrupt;
    }

    public function traces(): array
    {
        return $this->traces;
    }

    public function error(): ?array
    {
        return $this->run['error'] ?? null;
    }

    public function meta(): array
    {
        return $this->run['meta'] ?? [];
    }

    public function toRunResult(): RunResult
    {
        return new RunResult($this->run, $this->state(), $this->interrupt);
    }
}
