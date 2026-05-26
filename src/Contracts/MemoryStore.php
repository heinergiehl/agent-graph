<?php

namespace Heiner\AgentGraph\Contracts;

use Heiner\AgentGraph\Memory\MemoryScope;

interface MemoryStore
{
    public function write(MemoryScope $scope, string $namespace, string $key, mixed $value, string $type = 'fact', ?string $content = null, array $meta = []): array;

    /**
     * @param  array<int, MemoryScope>  $scopes
     */
    public function read(array $scopes, string $namespace, string $key): ?array;

    /**
     * @param  array<int, MemoryScope>  $scopes
     */
    public function search(array $scopes, string $query, ?string $namespace = null, ?string $type = null): array;

    public function exportScope(MemoryScope $scope, ?string $namespace = null): array;

    public function deleteScope(MemoryScope $scope): int;

    public function deleteNamespace(MemoryScope $scope, string $namespace): int;

    public function deleteKey(MemoryScope $scope, string $namespace, string $key): int;
}
