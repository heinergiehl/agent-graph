<?php

use Heiner\AgentGraph\Memory\MemoryScope;

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
