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

class RunGraphJob implements ShouldQueue
{
    use ConfiguresAgentGraphQueueJob;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $graphKey,
        public string $threadId,
        public array $input = [],
        public array $meta = [],
    ) {
        $this->configureAgentGraphQueueJob();
    }

    public function handle(AgentGraphManager $manager): RunResult
    {
        return $manager->graph($this->graphKey)
            ->thread($this->threadId)
            ->input($this->input)
            ->meta($this->meta)
            ->run();
    }

    public function tags(): array
    {
        return [
            'agent-graph',
            'agent-graph:run',
            'agent-graph:graph:'.$this->graphKey,
            'agent-graph:thread:'.$this->threadId,
        ];
    }
}
