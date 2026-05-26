<?php

namespace Heiner\AgentGraph\Runtime;

class NodeResult
{
    protected array $meta = [];

    protected function __construct(
        protected string $status,
        protected array $writes = [],
        protected ?string $nextNode = null,
        protected ?string $interruptType = null,
        protected array $interruptPayload = [],
        protected ?string $failureMessage = null,
    ) {}

    public static function write(array $writes): self
    {
        return new self('continue', $writes);
    }

    public static function goto(string $node, array $writes = []): self
    {
        return new self('continue', $writes, $node);
    }

    public static function interrupt(string $type, array $payload = [], array $writes = []): self
    {
        return new self('interrupted', $writes, null, $type, $payload);
    }

    public static function end(array $writes = []): self
    {
        return new self('completed', $writes);
    }

    public static function fail(string $message, array $meta = []): self
    {
        return (new self('failed', failureMessage: $message))->withMeta($meta);
    }

    public function withMeta(array $meta): self
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    public function withNodeMeta(array $meta): self
    {
        $existing = is_array($this->meta['node'] ?? null) ? $this->meta['node'] : [];
        $this->meta['node'] = array_merge($existing, $meta);

        return $this;
    }

    public function skipped(): self
    {
        return $this->withNodeMeta(['status' => 'skipped']);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function writes(): array
    {
        return $this->writes;
    }

    public function nextNode(): ?string
    {
        return $this->nextNode;
    }

    public function interruptType(): ?string
    {
        return $this->interruptType;
    }

    public function interruptPayload(): array
    {
        return $this->interruptPayload;
    }

    public function failureMessage(): ?string
    {
        return $this->failureMessage;
    }

    public function meta(): array
    {
        return $this->meta;
    }
}
