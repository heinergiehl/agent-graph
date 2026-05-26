<?php

namespace Heiner\AgentGraph\Contracts;

interface TaskStore
{
    public function findByKey(string $key): ?array;

    public function list(array $filters = [], int $limit = 50): array;

    public function start(string $key, string $inputHash, array $input, array $context = []): array;

    public function complete(string $key, mixed $result): array;

    public function fail(string $key, string $message, array $meta = []): array;
}
