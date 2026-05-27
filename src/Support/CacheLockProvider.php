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
            return cache()
                ->lock($key, (int) config('agent-graph.locks.ttl_seconds', 300))
                ->block((int) config('agent-graph.locks.block_seconds', 5), $callback);
        }

        return $callback();
    }
}
