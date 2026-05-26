<?php

namespace Heiner\AgentGraph\Contracts;

use DateTimeInterface;

interface DelayScheduler
{
    public function schedule(string $runId, string $interruptId, DateTimeInterface $resumeAt, array $payload = []): void;
}
