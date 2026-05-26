<?php

namespace Heiner\AgentGraph\Runtime;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Heiner\AgentGraph\Events\GraphEvent;

class RunEvent
{
    public function __construct(
        protected string $type,
        protected ?string $runId = null,
        protected ?string $threadId = null,
        protected ?string $graphKey = null,
        protected ?string $nodeId = null,
        protected array $payload = [],
        protected ?DateTimeInterface $timestamp = null,
    ) {
        $this->timestamp ??= CarbonImmutable::now();
    }

    public static function fromGraphEvent(string $type, GraphEvent $event): self
    {
        return new self(
            type: $type,
            runId: $event->runId,
            threadId: $event->threadId,
            graphKey: $event->graphKey,
            nodeId: $event->nodeId,
            payload: $event->payload,
        );
    }

    public function type(): string
    {
        return $this->type;
    }

    public function runId(): ?string
    {
        return $this->runId;
    }

    public function threadId(): ?string
    {
        return $this->threadId;
    }

    public function graphKey(): ?string
    {
        return $this->graphKey;
    }

    public function nodeId(): ?string
    {
        return $this->nodeId;
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function timestamp(): DateTimeInterface
    {
        return $this->timestamp;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'run_id' => $this->runId,
            'thread_id' => $this->threadId,
            'graph_key' => $this->graphKey,
            'node_id' => $this->nodeId,
            'payload' => $this->payload,
            'timestamp' => $this->timestamp->format(DateTimeInterface::ATOM),
        ];
    }
}
