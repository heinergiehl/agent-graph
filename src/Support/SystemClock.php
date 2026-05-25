<?php

namespace Heiner\AgentGraph\Support;

use Carbon\CarbonImmutable;
use Heiner\AgentGraph\Contracts\Clock;

class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
