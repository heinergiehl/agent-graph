<?php

namespace Heiner\AgentGraph\Contracts;

interface InterruptStore
{
    public function create(array $interrupt): array;

    public function find(string $interruptId): ?array;

    public function listForRun(string $runId): array;

    public function latestForRun(string $runId): ?array;

    public function pendingForRun(string $runId): ?array;

    public function resolve(string $interruptId, array $response, ?string $resolvedBy = null): array;
}
