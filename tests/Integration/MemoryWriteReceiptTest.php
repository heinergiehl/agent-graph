<?php

use Heiner\AgentGraph\Events\GraphMemoryRead;
use Heiner\AgentGraph\Events\GraphMemoryWritten;
use Heiner\AgentGraph\Memory\MemoryScope;
use Heiner\AgentGraph\Persistence\DatabaseMemoryStore;
use Heiner\AgentGraph\Persistence\InMemoryMemoryStore;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
});

it('returns memory write receipts without applying read expiry or usage side effects', function (string $driver, int $expiryMinutes) {
    $store = $driver === 'database' ? new DatabaseMemoryStore(app('db')) : new InMemoryMemoryStore;
    $scope = MemoryScope::thread('thread', tenantId: 'tenant');
    Event::fake([GraphMemoryRead::class, GraphMemoryWritten::class]);
    $meta = ['expires_at' => now()->addMinutes($expiryMinutes)];

    $written = $store->write($scope, 'context', 'entry', 'first', meta: $meta);
    $updated = $store->write($scope, 'context', 'entry', 'updated', meta: $meta);

    expect($written['usage_count'])->toBe(0)
        ->and($updated['id'])->toBe($written['id'])
        ->and($updated['value'])->toBe('updated')
        ->and($updated['usage_count'])->toBe(0)
        ->and($updated['last_used_at'] ?? null)->toBeNull();
    Event::assertDispatchedTimes(GraphMemoryWritten::class, 2);
    Event::assertNotDispatched(GraphMemoryRead::class);

    $read = $store->read([$scope], 'context', 'entry');
    if ($expiryMinutes <= 0) {
        expect($read)->toBeNull();
    } else {
        expect($read['value'])->toBe('updated')->and($read['usage_count'])->toBe(1);
    }
})->with(['database', 'memory'])->with([-1, 0, 1]);
