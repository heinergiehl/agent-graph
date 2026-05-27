<?php

namespace Heiner\AgentGraph\Memory;

use Heiner\AgentGraph\Contracts\VectorMemoryStore;
use Heiner\AgentGraph\Persistence\Concerns\UsesAgentGraphDatabaseConnection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

class PgvectorMemoryStore implements VectorMemoryStore
{
    use UsesAgentGraphDatabaseConnection;

    public function __construct(protected DatabaseManager $db) {}

    public function upsert(MemoryScope $scope, string $namespace, string $key, array $embedding, array $memory): array
    {
        $vector = $this->formatEmbedding($embedding);

        $attributes = [
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
            'tenant_id' => $scope->tenantId,
            'namespace' => $namespace,
            'key' => $key,
            'embedding' => $vector,
            'memory' => json_encode($memory, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];

        $this->query()->updateOrInsert([
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
        if ($scopes === [] || $limit <= 0) {
            return [];
        }

        $vector = $this->formatEmbedding($embedding);

        $query = $this->query()->where('namespace', $namespace);

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
            ->orderByRaw('embedding <=> ?::vector', [$vector])
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

    protected function formatEmbedding(array $embedding): string
    {
        if ($embedding === []) {
            throw new InvalidArgumentException('Pgvector embedding must not be empty.');
        }

        if (! array_is_list($embedding)) {
            throw new InvalidArgumentException('Pgvector embedding must be a list of finite numeric values.');
        }

        foreach ($embedding as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException('Pgvector embedding must contain only finite numeric values.');
            }
        }

        return '['.implode(',', array_map(
            static fn (int|float $value): string => sprintf('%.17G', (float) $value),
            $embedding,
        )).']';
    }
}
