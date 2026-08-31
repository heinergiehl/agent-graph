<?php

namespace Heiner\AgentGraph\Contracts;

interface NodeExecutionStore
{
    public function schedule(array $execution): array;

    public function record(array $execution): array;

    public function find(string $executionId): ?array;

    /**
     * A running claim contains a new claim_token. Terminal records are returned
     * unchanged without granting ownership to the caller.
     */
    public function claim(string $executionId, mixed $lockedUntil): ?array;

    public function complete(string $executionId, string $claimToken, array $result): array;

    public function interrupt(string $executionId, string $claimToken, array $result): array;

    public function fail(string $executionId, string $claimToken, array $error): array;

    public function listForRun(string $runId): array;

    public function listForRunStep(string $runId, int $step): array;
}
