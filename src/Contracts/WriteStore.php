<?php

namespace Heiner\AgentGraph\Contracts;

interface WriteStore
{
    public function createMany(string $runId, string $checkpointId, string $nodeId, array $writes, array $meta = []): void;

    public function listForCheckpoint(string $checkpointId): array;

    public function listForRun(string $runId): array;
}
