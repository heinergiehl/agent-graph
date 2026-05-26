<?php

namespace Heiner\AgentGraph\Runtime;

class RunTimeline
{
    /**
     * @param  array<int, RunTimelineStep>  $steps
     */
    public function __construct(
        protected array $run,
        protected array $steps,
    ) {}

    public function run(): array
    {
        return $this->run;
    }

    public function runId(): string
    {
        return $this->run['public_id'];
    }

    public function threadId(): string
    {
        return $this->run['thread_id'];
    }

    public function graphKey(): string
    {
        return $this->run['graph_key'];
    }

    public function graphVersion(): string
    {
        return $this->run['graph_version'];
    }

    public function status(): string
    {
        return $this->run['status'];
    }

    /**
     * @return array<int, RunTimelineStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function toArray(): array
    {
        return [
            'run_id' => $this->runId(),
            'thread_id' => $this->threadId(),
            'graph_key' => $this->graphKey(),
            'graph_version' => $this->graphVersion(),
            'status' => $this->status(),
            'steps' => array_map(
                static fn (RunTimelineStep $step): array => $step->toArray(),
                $this->steps,
            ),
        ];
    }
}
