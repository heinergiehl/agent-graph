<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\TraceStore;
use Heiner\AgentGraph\Tracing\RedactsTracePayloads;

class InMemoryTraceStore implements TraceStore
{
    use RedactsTracePayloads;

    protected array $traces = [];

    public function record(string $runId, string $event, array $payload = [], array $meta = []): array
    {
        $trace = [
            'id' => count($this->traces) + 1,
            'run_id' => $runId,
            'event' => $event,
            'payload' => $this->redactPayload($payload),
            'meta' => $this->redactPayload($meta),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->traces[] = $trace;

        return $trace;
    }

    public function listForRun(string $runId): array
    {
        return array_values(array_filter($this->traces, fn (array $trace): bool => $trace['run_id'] === $runId));
    }
}
