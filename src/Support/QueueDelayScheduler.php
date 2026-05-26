<?php

namespace Heiner\AgentGraph\Support;

use DateTimeInterface;
use Heiner\AgentGraph\Contracts\DelayScheduler;
use Heiner\AgentGraph\Queue\ContinueDelayedGraphJob;

class QueueDelayScheduler implements DelayScheduler
{
    public function schedule(string $runId, string $interruptId, DateTimeInterface $resumeAt, array $payload = []): void
    {
        ContinueDelayedGraphJob::dispatch($runId, array_merge($payload, [
            'interrupt_id' => $interruptId,
        ]))->delay($resumeAt);
    }
}
