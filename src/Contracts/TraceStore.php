<?php

namespace Heiner\AgentGraph\Contracts;

interface TraceStore
{
    public function record(string $runId, string $event, array $payload = [], array $meta = []): array;

    public function listForRun(string $runId): array;
}
