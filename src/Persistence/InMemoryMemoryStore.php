<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\EnumerableMemoryStore;
use Heiner\AgentGraph\Events\GraphMemoryRead;
use Heiner\AgentGraph\Events\GraphMemoryWritten;
use Heiner\AgentGraph\Memory\MemoryScope;

class InMemoryMemoryStore implements EnumerableMemoryStore
{
    protected array $memories = [];

    public function write(MemoryScope $scope, string $namespace, string $key, mixed $value, string $type = 'fact', ?string $content = null, array $meta = []): array
    {
        $id = $this->identity($scope, $namespace, $key);
        $memory = array_merge($this->memories[$id] ?? [
            'id' => count($this->memories) + 1,
            'created_at' => now(),
            'usage_count' => 0,
        ], [
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
            'tenant_id' => $scope->tenantId,
            'namespace' => $namespace,
            'key' => $key,
            'memory_type' => $type,
            'value' => $value,
            'content' => $content,
            'confidence' => $meta['confidence'] ?? null,
            'source' => $meta['source'] ?? null,
            'expires_at' => $meta['expires_at'] ?? null,
            'meta' => $meta,
            'updated_at' => now(),
        ]);

        $this->memories[$id] = $memory;
        event(new GraphMemoryWritten(payload: ['scope' => $scope->type, 'namespace' => $namespace, 'key' => $key]));

        return $memory;
    }

    public function read(array $scopes, string $namespace, string $key): ?array
    {
        foreach ($this->orderScopes($scopes) as $scope) {
            $id = $this->identity($scope, $namespace, $key);

            if (isset($this->memories[$id]) && ! $this->isExpired($this->memories[$id])) {
                $this->memories[$id]['usage_count']++;
                $this->memories[$id]['last_used_at'] = now();
                event(new GraphMemoryRead(payload: ['scope' => $scope->type, 'namespace' => $namespace, 'key' => $key]));

                return $this->memories[$id];
            }
        }

        return null;
    }

    public function search(array $scopes, string $query, ?string $namespace = null, ?string $type = null): array
    {
        $scopeKeys = array_map(fn (MemoryScope $scope): string => $scope->type.':'.$scope->id.':'.$scope->tenantId, $this->orderScopes($scopes));
        $query = str($query)->lower()->toString();

        $results = array_keys(array_filter($this->memories, function (array $memory) use ($scopeKeys, $query, $namespace, $type): bool {
            $scopeKey = $memory['scope_type'].':'.$memory['scope_id'].':'.$memory['tenant_id'];

            if (! in_array($scopeKey, $scopeKeys, true)) {
                return false;
            }

            if ($namespace !== null && $memory['namespace'] !== $namespace) {
                return false;
            }

            if ($type !== null && $memory['memory_type'] !== $type) {
                return false;
            }

            if ($this->isExpired($memory)) {
                return false;
            }

            return str((string) $memory['content'])->lower()->contains($query)
                || str((string) $memory['key'])->lower()->contains($query)
                || str(json_encode($memory['value']))->lower()->contains($query);
        }));

        return array_map(function (string $id): array {
            $this->memories[$id]['usage_count']++;
            $this->memories[$id]['last_used_at'] = now();

            return $this->memories[$id];
        }, $results);
    }

    public function listNamespace(array $scopes, string $namespace): array
    {
        $results = [];

        foreach ($this->orderScopes($scopes) as $scope) {
            foreach ($this->memories as $memory) {
                if ($memory['scope_type'] !== $scope->type
                    || $memory['scope_id'] !== $scope->id
                    || $memory['tenant_id'] !== $scope->tenantId
                    || $memory['namespace'] !== $namespace
                    || $this->isExpired($memory)) {
                    continue;
                }

                $results[] = $memory;
            }
        }

        return $results;
    }

    public function exportScope(MemoryScope $scope, ?string $namespace = null): array
    {
        return array_values(array_filter($this->memories, function (array $memory) use ($scope, $namespace): bool {
            return $memory['scope_type'] === $scope->type
                && $memory['scope_id'] === $scope->id
                && $memory['tenant_id'] === $scope->tenantId
                && ($namespace === null || $memory['namespace'] === $namespace);
        }));
    }

    public function deleteScope(MemoryScope $scope): int
    {
        return $this->deleteWhere(fn (array $memory): bool => $memory['scope_type'] === $scope->type
            && $memory['scope_id'] === $scope->id
            && $memory['tenant_id'] === $scope->tenantId);
    }

    public function deleteNamespace(MemoryScope $scope, string $namespace): int
    {
        return $this->deleteWhere(fn (array $memory): bool => $memory['scope_type'] === $scope->type
            && $memory['scope_id'] === $scope->id
            && $memory['tenant_id'] === $scope->tenantId
            && $memory['namespace'] === $namespace);
    }

    public function deleteKey(MemoryScope $scope, string $namespace, string $key): int
    {
        return $this->deleteWhere(fn (array $memory): bool => $memory['scope_type'] === $scope->type
            && $memory['scope_id'] === $scope->id
            && $memory['tenant_id'] === $scope->tenantId
            && $memory['namespace'] === $namespace
            && $memory['key'] === $key);
    }

    protected function deleteWhere(callable $predicate): int
    {
        $deleted = 0;

        foreach ($this->memories as $id => $memory) {
            if ($predicate($memory)) {
                unset($this->memories[$id]);
                $deleted++;
            }
        }

        return $deleted;
    }

    protected function identity(MemoryScope $scope, string $namespace, string $key): string
    {
        return implode('|', [$scope->type, $scope->tenantId, $scope->id, $namespace, $key]);
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

    protected function isExpired(array $memory): bool
    {
        if (($memory['expires_at'] ?? null) === null) {
            return false;
        }

        return now()->greaterThanOrEqualTo($memory['expires_at']);
    }
}
