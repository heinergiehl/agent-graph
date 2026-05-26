<?php

namespace Heiner\AgentGraph\Contracts;

use Heiner\AgentGraph\Memory\MemoryScope;

interface VectorMemoryStore
{
    public function upsert(MemoryScope $scope, string $namespace, string $key, array $embedding, array $memory): array;

    public function search(array $scopes, string $namespace, array $embedding, int $limit = 10): array;
}
