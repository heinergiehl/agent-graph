<?php

namespace Heiner\AgentGraph\Contracts;

interface NodeExecutionStore
{
    public function record(array $execution): array;

    public function listForRun(string $runId): array;
}
