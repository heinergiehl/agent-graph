<?php

namespace Heiner\AgentGraph\Queue;

use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Queue\Concerns\ConfiguresAgentGraphQueueJob;
use Heiner\AgentGraph\Runtime\RunResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ResumeGraphJob implements ShouldQueue
{
    use ConfiguresAgentGraphQueueJob;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $runId,
        public array $payload = [],
    ) {
        $this->configureAgentGraphQueueJob();
    }

    public function handle(AgentGraphManager $manager): RunResult
    {
        return $manager->resume($this->runId, $this->payload);
    }

    public function tags(): array
    {
        return [
            'agent-graph',
            'agent-graph:resume',
            'agent-graph:run:'.$this->runId,
        ];
    }
}
