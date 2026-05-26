<?php

namespace Heiner\AgentGraph\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;

class NodeExecutionJob implements ShouldQueue
{
    public function __construct(
        public readonly string $runId,
        public readonly string $nodeId,
        public readonly array $state = [],
        public readonly int $step = 0,
        public readonly int $scheduleIndex = 0,
    ) {}

    public function handle(): void {}
}
