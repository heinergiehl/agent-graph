<?php

namespace Heiner\AgentGraph\Memory;

use Heiner\AgentGraph\Contracts\EmbeddingGenerator;
use Heiner\AgentGraph\Contracts\EnumerableMemoryStore;
use Heiner\AgentGraph\Contracts\MemoryExtractor;
use Heiner\AgentGraph\Contracts\MemoryStore;
use Heiner\AgentGraph\Contracts\VectorMemoryStore;

class MemoryManager
{
    public function __construct(
        protected MemoryStore $store,
        protected MemoryExtractor $extractor,
        protected EmbeddingGenerator $embeddings,
        protected VectorMemoryStore $vectors,
    ) {}

    public function writeExtracted(MemoryScope $scope, string $namespace, string $text, array $meta = []): array
    {
        $written = [];

        foreach ($this->extractor->extract($text, $meta) as $memory) {
            $record = $this->store->write(
                $scope,
                $namespace,
                (string) $memory['key'],
                $memory['value'] ?? ['content' => $memory['content'] ?? ''],
                (string) ($memory['type'] ?? 'fact'),
                $memory['content'] ?? null,
                $memory['meta'] ?? [],
            );
            $written[] = $record;
        }

        return $written;
    }

    public function export(MemoryScope $scope, ?string $namespace = null): array
    {
        if ($namespace !== null && $this->store instanceof EnumerableMemoryStore) {
            return $this->store->listNamespace([$scope], $namespace);
        }

        return $this->store->exportScope($scope, $namespace);
    }

    public function deleteScope(MemoryScope $scope): int
    {
        return $this->store->deleteScope($scope);
    }

    public function deleteNamespace(MemoryScope $scope, string $namespace): int
    {
        return $this->store->deleteNamespace($scope, $namespace);
    }

    public function deleteKey(MemoryScope $scope, string $namespace, string $key): int
    {
        return $this->store->deleteKey($scope, $namespace, $key);
    }

    public function embeddings(): EmbeddingGenerator
    {
        return $this->embeddings;
    }

    public function vectors(): VectorMemoryStore
    {
        return $this->vectors;
    }
}
