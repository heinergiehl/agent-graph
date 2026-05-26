<?php

namespace Heiner\AgentGraph\Memory;

use Heiner\AgentGraph\Contracts\VectorMemoryStore;
use Illuminate\Database\DatabaseManager;

class PgvectorMemoryStore implements VectorMemoryStore
{
    public function __construct(protected DatabaseManager $db) {}

    public function upsert(MemoryScope $scope, string $namespace, string $key, array $embedding, array $memory): array
    {
        $attributes = [
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
            'tenant_id' => $scope->tenantId,
            'namespace' => $namespace,
            'key' => $key,
            'embedding' => '['.implode(',', $embedding).']',
            'memory' => json_encode($memory, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];

        $this->db->table($this->table())->updateOrInsert([
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
            'tenant_id' => $scope->tenantId,
            'namespace' => $namespace,
            'key' => $key,
        ], array_merge($attributes, ['created_at' => now()]));

        return array_merge($attributes, ['memory' => $memory]);
    }

    public function search(array $scopes, string $namespace, array $embedding, int $limit = 10): array
    {
        $query = $this->db->table($this->table())->where('namespace', $namespace);

        $query->where(function ($builder) use ($scopes): void {
            foreach ($scopes as $scope) {
                $builder->orWhere(function ($nested) use ($scope): void {
                    $nested->where('scope_type', $scope->type)
                        ->where('scope_id', $scope->id)
                        ->where('tenant_id', $scope->tenantId);
                });
            }
        });

        return $query
            ->orderByRaw('embedding <=> ?::vector', ['['.implode(',', $embedding).']'])
            ->limit($limit)
            ->get()
            ->map(fn (object $record): array => [
                'scope_type' => $record->scope_type,
                'scope_id' => $record->scope_id,
                'tenant_id' => $record->tenant_id,
                'namespace' => $record->namespace,
                'key' => $record->key,
                'memory' => json_decode($record->memory, true, flags: JSON_THROW_ON_ERROR),
            ])
            ->all();
    }

    protected function table(): string
    {
        return config('agent-graph.vector_memory.table', 'agent_graph_vector_memories');
    }
}
