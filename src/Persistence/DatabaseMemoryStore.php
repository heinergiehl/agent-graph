<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\EnumerableMemoryStore;
use Heiner\AgentGraph\Events\GraphMemoryRead;
use Heiner\AgentGraph\Events\GraphMemoryWritten;
use Heiner\AgentGraph\Memory\MemoryScope;
use Heiner\AgentGraph\Persistence\Concerns\SerializesDatabaseValues;
use Heiner\AgentGraph\Persistence\Concerns\UsesAgentGraphDatabaseConnection;
use Illuminate\Database\DatabaseManager;

class DatabaseMemoryStore implements EnumerableMemoryStore
{
    use SerializesDatabaseValues;
    use UsesAgentGraphDatabaseConnection;

    public function __construct(protected DatabaseManager $db) {}

    public function write(MemoryScope $scope, string $namespace, string $key, mixed $value, string $type = 'fact', ?string $content = null, array $meta = []): array
    {
        $now = now();
        $query = $this->query()
            ->where('scope_type', $scope->type)
            ->where('scope_id', $scope->id)
            ->where('tenant_id', $scope->tenantId)
            ->where('namespace', $namespace)
            ->where('key', $key);

        $attributes = [
            'memory_type' => $type,
            'value' => $this->encode($value),
            'content' => $content,
            'confidence' => $meta['confidence'] ?? null,
            'source' => $meta['source'] ?? null,
            'expires_at' => $meta['expires_at'] ?? null,
            'meta' => $this->encode($meta),
            'updated_at' => $now,
        ];

        if ($query->exists()) {
            $query->update($attributes);
        } else {
            $this->query()->insert(array_merge($attributes, [
                'scope_type' => $scope->type,
                'scope_id' => $scope->id,
                'tenant_id' => $scope->tenantId,
                'namespace' => $namespace,
                'key' => $key,
                'usage_count' => 0,
                'created_at' => $now,
            ]));
        }

        event(new GraphMemoryWritten(payload: ['scope' => $scope->type, 'namespace' => $namespace, 'key' => $key]));

        return $this->read([$scope], $namespace, $key);
    }

    public function read(array $scopes, string $namespace, string $key): ?array
    {
        foreach ($this->orderScopes($scopes) as $scope) {
            $record = $this->queryForScope($scope)
                ->where(function ($builder): void {
                    $builder->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->where('namespace', $namespace)
                ->where('key', $key)
                ->first();

            if ($record !== null) {
                $this->query()->where('id', $record->id)->update([
                    'usage_count' => $record->usage_count + 1,
                    'last_used_at' => now(),
                    'updated_at' => now(),
                ]);
                event(new GraphMemoryRead(payload: ['scope' => $scope->type, 'namespace' => $namespace, 'key' => $key]));

                $record = $this->query()->where('id', $record->id)->first();

                return $this->decodeRecord($record, ['value', 'meta']);
            }
        }

        return null;
    }

    public function search(array $scopes, string $query, ?string $namespace = null, ?string $type = null): array
    {
        $results = [];

        foreach ($this->orderScopes($scopes) as $scope) {
            $builder = $this->queryForScope($scope)
                ->where(function ($builder): void {
                    $builder->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->where(function ($builder) use ($query): void {
                    $builder->where('content', 'like', "%{$query}%")
                        ->orWhere('key', 'like', "%{$query}%")
                        ->orWhere('value', 'like', "%{$query}%");
                });

            if ($namespace !== null) {
                $builder->where('namespace', $namespace);
            }

            if ($type !== null) {
                $builder->where('memory_type', $type);
            }

            foreach ($builder->get() as $record) {
                $this->query()->where('id', $record->id)->update([
                    'usage_count' => $record->usage_count + 1,
                    'last_used_at' => now(),
                    'updated_at' => now(),
                ]);

                $record = $this->query()->where('id', $record->id)->first();
                $results[] = $this->decodeRecord($record, ['value', 'meta']);
            }
        }

        return $results;
    }

    public function listNamespace(array $scopes, string $namespace): array
    {
        $results = [];

        foreach ($this->orderScopes($scopes) as $scope) {
            $records = $this->queryForScope($scope)
                ->where(function ($builder): void {
                    $builder->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->where('namespace', $namespace)
                ->orderBy('id')
                ->get();

            foreach ($records as $record) {
                $results[] = $this->decodeRecord($record, ['value', 'meta']);
            }
        }

        return $results;
    }

    public function exportScope(MemoryScope $scope, ?string $namespace = null): array
    {
        $query = $this->queryForScope($scope);

        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }

        return $query
            ->orderBy('id')
            ->get()
            ->map(fn ($record): array => $this->decodeRecord($record, ['value', 'meta']))
            ->all();
    }

    public function deleteScope(MemoryScope $scope): int
    {
        return $this->queryForScope($scope)->delete();
    }

    public function deleteNamespace(MemoryScope $scope, string $namespace): int
    {
        return $this->queryForScope($scope)->where('namespace', $namespace)->delete();
    }

    public function deleteKey(MemoryScope $scope, string $namespace, string $key): int
    {
        return $this->queryForScope($scope)
            ->where('namespace', $namespace)
            ->where('key', $key)
            ->delete();
    }

    protected function queryForScope(MemoryScope $scope)
    {
        return $this->query()
            ->where('scope_type', $scope->type)
            ->where('scope_id', $scope->id)
            ->where('tenant_id', $scope->tenantId);
    }

    protected function table(): string
    {
        return config('agent-graph.tables.memories', 'agent_graph_memories');
    }

    /**
     * @param  array<int|string, MemoryScope>  $scopes
     * @return array<int, MemoryScope>
     */
    protected function orderScopes(array $scopes): array
    {
        $byType = [];
        $ordered = [];

        foreach ($scopes as $scope) {
            $byType[$scope->type][] = $scope;
            $ordered[] = $scope;
        }

        $result = [];
        $seen = [];

        foreach ((array) config('agent-graph.memory.fallback_order', []) as $type) {
            foreach ($byType[$type] ?? [] as $scope) {
                $id = $scope->type.':'.$scope->tenantId.':'.$scope->id;

                if (! isset($seen[$id])) {
                    $result[] = $scope;
                    $seen[$id] = true;
                }
            }
        }

        foreach ($ordered as $scope) {
            $id = $scope->type.':'.$scope->tenantId.':'.$scope->id;

            if (! isset($seen[$id])) {
                $result[] = $scope;
                $seen[$id] = true;
            }
        }

        return $result;
    }
}
