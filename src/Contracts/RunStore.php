<?php

namespace Heiner\AgentGraph\Contracts;

interface RunStore
{
    public function create(string $graphKey, string $graphVersion, string $threadId, array $input = [], array $meta = []): array;

    public function find(string $runId): ?array;

    public function list(array $filters = [], int $limit = 50): array;

    public function update(string $runId, array $attributes): array;
}
