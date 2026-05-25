<?php

use Heiner\AgentGraph\Contracts\EnumerableMemoryStore;
use Heiner\AgentGraph\Memory\MemoryScope;

it('lists namespace memories using fallback order and ignoring expired records', function () {
    $memory = app('agent-graph.memory');
    config()->set('agent-graph.memory.fallback_order', ['actor', 'thread']);

    expect($memory)->toBeInstanceOf(EnumerableMemoryStore::class);

    $thread = MemoryScope::thread('thread-namespace');
    $actor = MemoryScope::actor('tenant-namespace', 'user-namespace');

    $memory->write($thread, 'profile', 'language', 'en', 'preference', 'Thread language.');
    $memory->write($thread, 'profile', 'timezone', 'UTC', 'preference', 'Thread timezone.');
    $memory->write($actor, 'profile', 'language', 'de', 'preference', 'Actor language.');
    $memory->write($actor, 'profile', 'expired', 'old', 'preference', 'Expired.', [
        'expires_at' => now()->subMinute(),
    ]);

    $records = $memory->listNamespace([
        'thread' => $thread,
        'actor' => $actor,
    ], 'profile');

    expect($records)->toHaveCount(2)
        ->and($records[0]['key'])->toBe('language')
        ->and($records[0]['value'])->toBe('de')
        ->and($records[0]['usage_count'])->toBe(1)
        ->and($records[0]['last_used_at'])->not->toBeNull()
        ->and($records[1]['key'])->toBe('timezone')
        ->and($records[1]['value'])->toBe('UTC')
        ->and(collect($records)->pluck('key')->all())->not->toContain('expired');
});

it('writes and reads scoped memory with fallback order', function () {
    $memory = app('agent-graph.memory');
    config()->set('agent-graph.memory.fallback_order', ['actor', 'thread']);

    $memory->write(
        scope: MemoryScope::actor('tenant-1', 'user-1'),
        namespace: 'preferences',
        key: 'language',
        value: 'de',
        type: 'preference',
        content: 'The user prefers German.',
    );

    $memory->write(
        scope: MemoryScope::thread('thread-1'),
        namespace: 'preferences',
        key: 'language',
        value: 'en',
        type: 'preference',
        content: 'Thread-level fallback value.',
    );

    $result = $memory->read(
        scopes: [
            'thread' => MemoryScope::thread('thread-1'),
            'actor' => MemoryScope::actor('tenant-1', 'user-1'),
        ],
        namespace: 'preferences',
        key: 'language',
    );

    expect($result)->not->toBeNull()
        ->and($result['value'])->toBe('de')
        ->and($memory->search([MemoryScope::actor('tenant-1', 'user-1')], 'German'))->toHaveCount(1);
});

it('ignores expired memory and updates usage metadata on read and search', function () {
    $memory = app('agent-graph.memory');
    $scope = MemoryScope::actor('tenant-2', 'user-2');

    $memory->write(
        scope: $scope,
        namespace: 'preferences',
        key: 'expired',
        value: 'old',
        type: 'preference',
        content: 'Expired preference.',
        meta: ['expires_at' => now()->subMinute()],
    );

    $memory->write(
        scope: $scope,
        namespace: 'preferences',
        key: 'fresh',
        value: 'new',
        type: 'preference',
        content: 'Fresh preference.',
        meta: ['expires_at' => now()->addDay()],
    );

    expect($memory->read([$scope], 'preferences', 'expired'))->toBeNull();

    $fresh = $memory->read([$scope], 'preferences', 'fresh');

    expect($fresh['usage_count'])->toBe(1)
        ->and($fresh['last_used_at'])->not->toBeNull();

    $results = $memory->search([$scope], 'preference', 'preferences', 'preference');

    expect($results)->toHaveCount(1)
        ->and($results[0]['key'])->toBe('fresh')
        ->and($results[0]['usage_count'])->toBe(2);
});
