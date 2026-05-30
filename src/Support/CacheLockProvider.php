<?php

namespace Heiner\AgentGraph\Support;

use Closure;
use Heiner\AgentGraph\Contracts\LockProvider;
use RuntimeException;

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

        if ((bool) config('agent-graph.locks.fail_without_provider', true)) {
            throw new RuntimeException('Cache store does not support atomic locks. Configure a lock-capable cache store or set agent-graph.locks.fail_without_provider=false for local throwaway runs.');
        }

        return $callback();
    }
}
