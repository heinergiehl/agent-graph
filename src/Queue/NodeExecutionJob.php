<?php

namespace Heiner\AgentGraph\Queue;

use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Queue\Concerns\ConfiguresAgentGraphQueueJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NodeExecutionJob implements ShouldQueue
{
    use ConfiguresAgentGraphQueueJob;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $executionId)
    {
        $this->configureAgentGraphQueueJob();
    }

    public function handle(AgentGraphManager $manager): void
    {
        $manager->executeQueuedNode($this->executionId);
    }

    public function tags(): array
    {
        return [
            'agent-graph',
            'agent-graph:node-execution',
            'agent-graph:execution:'.$this->executionId,
        ];
    }
}
