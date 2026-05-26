<?php

namespace Heiner\AgentGraph\Graph;

use InvalidArgumentException;

class ConcurrencyPolicy
{
    public function __construct(
        protected int $limit = 1,
        protected ?string $key = null,
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException('Concurrency limit must be at least one.');
        }
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function key(): ?string
    {
        return $this->key;
    }
}
