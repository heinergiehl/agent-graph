<?php

namespace Heiner\AgentGraph\Support;

use Heiner\AgentGraph\Contracts\DelayScheduler;

final class DelaySchedulerResolver
{
    public function resolve(): DelayScheduler
    {
        return app(DelayScheduler::class);
    }
}
