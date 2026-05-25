<?php

namespace Heiner\AgentGraph\Support;

use Closure;
use Heiner\AgentGraph\Contracts\LockProvider;

class CacheLockProvider implements LockProvider
{
    public function withLock(string $key, Closure $callback): mixed
    {
        $store = cache()->getStore();

        if (method_exists($store, 'lock')) {
            return cache()->lock($key, 30)->block(5, $callback);
        }

        return $callback();
    }
}
