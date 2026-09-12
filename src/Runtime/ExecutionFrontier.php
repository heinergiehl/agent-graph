<?php

namespace Heiner\AgentGraph\Runtime;

/** @internal A committed schedule awaiting delivery, bound to its run revision. */
final readonly class ExecutionFrontier
{
    public function __construct(
        public array $run,
        public int $step,
        public array $executions,
    ) {}
}
