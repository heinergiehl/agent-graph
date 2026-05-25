<?php

namespace Heiner\AgentGraph\Contracts;

use DateTimeInterface;

interface DelayScheduler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function schedule(string $runId, array $payload, DateTimeInterface $resumeAt): void;
}
