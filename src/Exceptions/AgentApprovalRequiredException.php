<?php

namespace Heiner\AgentGraph\Exceptions;

use RuntimeException;

class AgentApprovalRequiredException extends RuntimeException
{
    public function __construct(public readonly string $nodeId)
    {
        parent::__construct("Agent node [{$nodeId}] is waiting for Laravel AI tool approval. Native tool approval resumption is not supported by AgentNode; use graph approval interrupts before invoking the agent.");
    }
}
