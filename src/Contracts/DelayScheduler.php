<?php

namespace Heiner\AgentGraph\Contracts;

use DateTimeInterface;

interface DelayScheduler
{
    public function schedule(string $runId, array $payload, DateTimeInterface $resumeAt): void;
}
