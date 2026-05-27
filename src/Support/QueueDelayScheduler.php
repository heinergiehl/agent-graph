<?php

namespace Heiner\AgentGraph\Support;

use DateTimeInterface;
use Heiner\AgentGraph\Contracts\DelayScheduler;
use Heiner\AgentGraph\Queue\ContinueDelayedGraphJob;

class QueueDelayScheduler implements DelayScheduler
{
    public function schedule(string $runId, array $payload, DateTimeInterface $resumeAt): void
    {
        AgentGraphQueue::configure(ContinueDelayedGraphJob::dispatch($runId, $payload))->delay($resumeAt);
    }
}
