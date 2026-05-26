<?php

namespace Heiner\AgentGraph\Graph;

use InvalidArgumentException;

class TimeoutPolicy
{
    public function __construct(protected float $seconds)
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('Timeout seconds must be greater than zero.');
        }
    }

    public function seconds(): float
    {
        return $this->seconds;
    }
}
