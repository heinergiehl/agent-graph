<?php

namespace Heiner\AgentGraph\Graph;

class NodePolicy
{
    public function __construct(
        protected ?RetryPolicy $retryPolicy = null,
        protected ?TimeoutPolicy $timeoutPolicy = null,
        protected ?ConcurrencyPolicy $concurrencyPolicy = null,
    ) {}

    public static function default(): self
    {
        return new self;
    }

    public function retryPolicy(): ?RetryPolicy
    {
        return $this->retryPolicy;
    }

    public function timeoutPolicy(): ?TimeoutPolicy
    {
        return $this->timeoutPolicy;
    }

    public function concurrencyPolicy(): ?ConcurrencyPolicy
    {
        return $this->concurrencyPolicy;
    }

    public function withRetryPolicy(RetryPolicy $retryPolicy): self
    {
        return new self($retryPolicy, $this->timeoutPolicy, $this->concurrencyPolicy);
    }

    public function withTimeoutPolicy(TimeoutPolicy $timeoutPolicy): self
    {
        return new self($this->retryPolicy, $timeoutPolicy, $this->concurrencyPolicy);
    }

    public function withConcurrencyPolicy(ConcurrencyPolicy $concurrencyPolicy): self
    {
        return new self($this->retryPolicy, $this->timeoutPolicy, $concurrencyPolicy);
    }
}
