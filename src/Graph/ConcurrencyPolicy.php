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

        if ($limit !== 1) {
            throw new InvalidArgumentException('AgentGraph currently supports only exclusive node concurrency with limit=1. Semaphore limits greater than 1 are not implemented.');
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
