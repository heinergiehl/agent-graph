<?php

namespace Heiner\AgentGraph\Contracts;

use DateTimeInterface;

interface LeasingTaskStore extends TaskStore
{
    public function activeLeaseUntil(array $task): ?DateTimeInterface;
}
