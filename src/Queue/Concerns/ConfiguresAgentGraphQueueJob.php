<?php

namespace Heiner\AgentGraph\Queue\Concerns;

trait ConfiguresAgentGraphQueueJob
{
    public int $tries;

    public int $timeout;

    protected function configureAgentGraphQueueJob(): void
    {
        $this->tries = (int) config('agent-graph.execution.job_tries', 3);
        $this->timeout = (int) config('agent-graph.execution.job_timeout', 300);
    }

    public function backoff(): int|array
    {
        $backoff = config('agent-graph.execution.job_backoff', [5]);

        if (is_string($backoff)) {
            return array_map('intval', explode(',', $backoff));
        }

        if (is_array($backoff)) {
            return array_map('intval', $backoff);
        }

        return (int) $backoff;
    }
}
