<?php

namespace Heiner\AgentGraph\Support;

use DateTimeInterface;
use Heiner\AgentGraph\Contracts\DelayScheduler;
use Heiner\AgentGraph\Queue\ContinueDelayedGraphJob;

class QueuedDelayScheduler implements DelayScheduler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function schedule(string $runId, array $payload, DateTimeInterface $resumeAt): void
    {
        ContinueDelayedGraphJob::dispatch($runId, $payload)->delay($resumeAt);
    }
}
