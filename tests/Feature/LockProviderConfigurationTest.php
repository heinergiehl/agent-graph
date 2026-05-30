<?php

use Heiner\AgentGraph\Support\CacheLockProvider;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;

it('throws when cache store has no atomic lock support and fail closed is enabled', function () {
    config()->set('agent-graph.locks.fail_without_provider', true);
    app()->instance('cache', new Repository(new NonLockingTestCacheStore));

    expect(fn () => (new CacheLockProvider)->withLock('agent-graph:test', fn () => 'ran'))
        ->toThrow(RuntimeException::class, 'Cache store does not support atomic locks');
});

it('allows explicit fail open mode for local tests', function () {
    config()->set('agent-graph.locks.fail_without_provider', false);
    app()->instance('cache', new Repository(new NonLockingTestCacheStore));

    expect((new CacheLockProvider)->withLock('agent-graph:test', fn () => 'ran'))->toBe('ran');
});

final class NonLockingTestCacheStore implements Store
{
    public function get($key)
    {
        return null;
    }

    public function many(array $keys)
    {
        return [];
    }

    public function put($key, $value, $seconds)
    {
        return true;
    }

    public function putMany(array $values, $seconds)
    {
        return true;
    }

    public function increment($key, $value = 1)
    {
        return false;
    }

    public function decrement($key, $value = 1)
    {
        return false;
    }

    public function forever($key, $value)
    {
        return true;
    }

    public function touch($key, $seconds)
    {
        return true;
    }

    public function forget($key)
    {
        return true;
    }

    public function flush()
    {
        return true;
    }

    public function getPrefix()
    {
        return '';
    }
}
