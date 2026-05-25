<?php

namespace Heiner\AgentGraph\Contracts;

interface CheckpointStore
{
    public function create(array $checkpoint): array;

    public function find(string $checkpointId): ?array;

    public function latestForRun(string $runId): ?array;

    public function listForRun(string $runId): array;
}
