<?php

namespace Heiner\AgentGraph\Exceptions;

use Laravel\Ai\Streaming\Events\Error;
use RuntimeException;

class AgentStreamException extends RuntimeException
{
    public function __construct(
        public readonly string $nodeId,
        public readonly Error $event,
    ) {
        parent::__construct("Agent node [{$nodeId}] stream failed [{$event->type}]: {$event->message}");
    }
}
