<?php

namespace Heiner\AgentGraph\Queue;

use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Queue\Concerns\ConfiguresAgentGraphQueueJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ContinueSuperstepJob implements ShouldQueue
{
    use ConfiguresAgentGraphQueueJob;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $runId,
        public readonly int $step,
    ) {
        $this->configureAgentGraphQueueJob();
    }

    public function handle(AgentGraphManager $manager): void
    {
        $manager->continueQueuedSuperstep($this->runId, $this->step);
    }

    public function tags(): array
    {
        return [
            'agent-graph',
            'agent-graph:continue-superstep',
            'agent-graph:run:'.$this->runId,
            'agent-graph:step:'.$this->step,
        ];
    }
}
