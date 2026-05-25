<?php

namespace Heiner\AgentGraph\Queue;

use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Runtime\RunResult;

class ContinueDelayedGraphJob extends ResumeGraphJob
{
    public function handle(AgentGraphManager $manager): RunResult
    {
        $snapshot = $manager->inspect($this->runId);

        if ($snapshot === null) {
            return parent::handle($manager);
        }

        if (in_array($snapshot->status(), ['completed', 'cancelled', 'failed'], true)) {
            return $snapshot->toRunResult();
        }

        $interrupt = $snapshot->interrupt();

        if ($interrupt === null
            || ($interrupt['interrupt_id'] ?? null) !== ($this->payload['interrupt_id'] ?? null)
            || ($interrupt['type'] ?? null) !== 'delay') {
            return $snapshot->toRunResult();
        }

        return parent::handle($manager);
    }
}
