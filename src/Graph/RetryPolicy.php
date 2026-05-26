<?php

namespace Heiner\AgentGraph\Graph;

use Closure;
use Heiner\AgentGraph\Runtime\NodeContext;
use InvalidArgumentException;
use ReflectionFunction;
use Throwable;

class RetryPolicy
{
    protected ?Closure $when;

    public function __construct(
        protected int $maxAttempts = 3,
        protected int $delayMs = 0,
        protected float $backoff = 1.0,
        protected ?int $maxDelayMs = null,
        ?callable $when = null,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be at least 1.');
        }

        if ($delayMs < 0) {
            throw new InvalidArgumentException('delayMs must be greater than or equal to 0.');
        }

        if ($backoff < 1.0) {
            throw new InvalidArgumentException('backoff must be greater than or equal to 1.');
        }

        if ($maxDelayMs !== null && $maxDelayMs < 0) {
            throw new InvalidArgumentException('maxDelayMs must be greater than or equal to 0.');
        }

        $this->when = $when === null ? null : Closure::fromCallable($when);
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function delayMs(): int
    {
        return $this->delayMs;
    }

    public function backoff(): float
    {
        return $this->backoff;
    }

    public function maxDelayMs(): ?int
    {
        return $this->maxDelayMs;
    }

    public function delayForAttempt(int $attempt): int
    {
        if ($attempt < 1 || $this->delayMs === 0) {
            return 0;
        }

        $delay = (int) round($this->delayMs * ($this->backoff ** ($attempt - 1)));

        if ($this->maxDelayMs !== null) {
            return min($delay, $this->maxDelayMs);
        }

        return $delay;
    }

    public function shouldRetry(Throwable $exception, int $attempt, NodeContext $context): bool
    {
        if ($this->when === null) {
            return true;
        }

        $arguments = [$exception, $attempt, $context];
        $parameterCount = min((new ReflectionFunction($this->when))->getNumberOfParameters(), count($arguments));

        return (bool) ($this->when)(...array_slice($arguments, 0, $parameterCount));
    }
}
