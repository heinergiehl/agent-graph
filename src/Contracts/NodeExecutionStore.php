<?php

namespace Heiner\AgentGraph\Contracts;

interface NodeExecutionStore
{
    public function schedule(array $execution): array;

    public function record(array $execution): array;

    public function find(string $executionId): ?array;

    public function claim(string $executionId, mixed $lockedUntil): ?array;

    public function complete(string $executionId, array $result): array;

    public function interrupt(string $executionId, array $result): array;

    public function fail(string $executionId, array $error): array;

    public function listForRun(string $runId): array;

    public function listForRunStep(string $runId, int $step): array;
}
