<?php

namespace Heiner\AgentGraph\Contracts;

use Closure;

interface LockProvider
{
    public function withLock(string $key, Closure $callback): mixed;
}
