<?php

use Heiner\AgentGraph\Memory\MemoryScope;
use Heiner\AgentGraph\Memory\PgvectorMemoryStore;
use Illuminate\Database\DatabaseManager;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

it('returns no vector memories for empty scopes without touching the database', function () {
    $db = m::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $store = new PgvectorMemoryStore($db);

    expect($store->search([], 'support', [1.0, 0.0, 0.0]))->toBe([]);
});

it('returns no vector memories for non positive limits without touching the database', function () {
    $db = m::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $store = new PgvectorMemoryStore($db);
    $scope = MemoryScope::thread('thread-1', 'tenant-1');

    expect($store->search([$scope], 'support', [1.0, 0.0, 0.0], limit: 0))->toBe([])
        ->and($store->search([$scope], 'support', [1.0, 0.0, 0.0], limit: -1))->toBe([]);
});

it('rejects empty pgvector embeddings before writing', function () {
    $db = m::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $store = new PgvectorMemoryStore($db);

    expect(fn () => $store->upsert(
        MemoryScope::thread('thread-1'),
        'support',
        'memory-1',
        [],
        ['value' => 'near'],
    ))->toThrow(InvalidArgumentException::class, 'must not be empty');
});

it('rejects non numeric and non finite pgvector embeddings before writing', function (array $embedding) {
    $db = m::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $store = new PgvectorMemoryStore($db);

    expect(fn () => $store->upsert(
        MemoryScope::thread('thread-1'),
        'support',
        'memory-1',
        $embedding,
        ['value' => 'near'],
    ))->toThrow(InvalidArgumentException::class, 'must contain only finite numeric values');
})->with([
    'string value' => [[1.0, 'bad']],
    'infinite value' => [[1.0, INF]],
    'nan value' => [[1.0, NAN]],
]);

it('rejects invalid pgvector search embeddings before querying', function () {
    $db = m::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $store = new PgvectorMemoryStore($db);

    expect(fn () => $store->search(
        [MemoryScope::thread('thread-1')],
        'support',
        [1.0, 'bad'],
    ))->toThrow(InvalidArgumentException::class, 'must contain only finite numeric values');
});
