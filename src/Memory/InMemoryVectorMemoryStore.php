<?php

namespace Heiner\AgentGraph\Memory;

use Heiner\AgentGraph\Contracts\VectorMemoryStore;

class InMemoryVectorMemoryStore implements VectorMemoryStore
{
    protected array $records = [];

    public function upsert(MemoryScope $scope, string $namespace, string $key, array $embedding, array $memory): array
    {
        $id = $scope->type.':'.$scope->tenantId.':'.$scope->id.':'.$namespace.':'.$key;

        return $this->records[$id] = [
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
            'tenant_id' => $scope->tenantId,
            'namespace' => $namespace,
            'key' => $key,
            'embedding' => $embedding,
            'memory' => $memory,
        ];
    }

    public function search(array $scopes, string $namespace, array $embedding, int $limit = 10): array
    {
        $scopeKeys = array_map(fn (MemoryScope $scope): string => $scope->type.':'.$scope->tenantId.':'.$scope->id, $scopes);

        return collect($this->records)
            ->filter(fn (array $record): bool => in_array($record['scope_type'].':'.$record['tenant_id'].':'.$record['scope_id'], $scopeKeys, true)
                && $record['namespace'] === $namespace)
            ->take($limit)
            ->values()
            ->all();
    }
}
