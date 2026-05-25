<?php

namespace Heiner\AgentGraph\Events;

class GraphEvent
{
    public function __construct(
        public readonly ?string $runId = null,
        public readonly ?string $threadId = null,
        public readonly ?string $graphKey = null,
        public readonly ?string $nodeId = null,
        public readonly array $payload = [],
    ) {}
}
