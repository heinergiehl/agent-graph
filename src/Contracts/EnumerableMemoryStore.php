<?php

namespace Heiner\AgentGraph\Contracts;

use Heiner\AgentGraph\Memory\MemoryScope;

interface EnumerableMemoryStore extends MemoryStore
{
    /**
     * @param  array<int|string, MemoryScope>  $scopes
     */
    public function listNamespace(array $scopes, string $namespace): array;
}
