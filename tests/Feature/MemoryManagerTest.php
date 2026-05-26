<?php

use Heiner\AgentGraph\Contracts\EmbeddingGenerator;
use Heiner\AgentGraph\Contracts\MemoryExtractor;
use Heiner\AgentGraph\Contracts\VectorMemoryStore;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Memory\MemoryScope;

it('exposes memory export delete and deterministic writer APIs', function () {
    $scope = MemoryScope::thread('memory-thread', tenantId: 'tenant-1');

    $written = AgentGraph::memory()->writeExtracted($scope, 'profile', 'Likes chess. Prefers email.', [
        'source' => 'test',
    ]);

    expect($written)->toHaveCount(2);

    $exported = AgentGraph::memory()->export($scope, 'profile');

    expect($exported)->toHaveCount(2)
        ->and($exported[0]['namespace'])->toBe('profile');

    AgentGraph::memory()->deleteNamespace($scope, 'profile');

    expect(AgentGraph::memory()->export($scope, 'profile'))->toBe([]);
});

it('provides vector memory contracts without making pgvector mandatory', function () {
    expect(app(MemoryExtractor::class))->toBeInstanceOf(MemoryExtractor::class)
        ->and(app(EmbeddingGenerator::class))->toBeInstanceOf(EmbeddingGenerator::class)
        ->and(app(VectorMemoryStore::class))->toBeInstanceOf(VectorMemoryStore::class);
});
