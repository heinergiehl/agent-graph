<?php

namespace Heiner\AgentGraph\Graph;

class NodePolicy
{
    public function __construct(
        protected ?RetryPolicy $retryPolicy = null,
    ) {}

    public static function default(): self
    {
        return new self;
    }

    public function retryPolicy(): ?RetryPolicy
    {
        return $this->retryPolicy;
    }

    public function withRetryPolicy(RetryPolicy $retryPolicy): self
    {
        return new self($retryPolicy);
    }
}
