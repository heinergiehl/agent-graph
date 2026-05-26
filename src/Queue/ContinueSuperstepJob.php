<?php

namespace Heiner\AgentGraph\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;

class ContinueSuperstepJob implements ShouldQueue
{
    public function __construct(public readonly string $runId) {}

    public function handle(): void {}
}
