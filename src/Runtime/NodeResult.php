<?php

namespace Heiner\AgentGraph\Runtime;

use Heiner\AgentGraph\Graph\InterruptPolicy;

class NodeResult
{
    protected array $meta = [];

    protected ?InterruptPolicy $interruptPolicy = null;

    protected function __construct(
        protected string $status,
        protected array $writes = [],
        protected ?string $nextNode = null,
        protected ?string $interruptType = null,
        protected array $interruptPayload = [],
        protected ?string $failureMessage = null,
        protected array $sends = [],
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

    public static function send(string $node, array $input = [], array $writes = []): self
    {
        return self::sendMany([Send::to($node, $input)], $writes);
    }

    public static function sendMany(array $sends, array $writes = []): self
    {
        return new self(
            status: 'continue',
            writes: $writes,
            sends: array_map(fn (mixed $send): Send => Send::from($send), $sends),
        );
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

    public function withInterruptPolicy(InterruptPolicy $policy): self
    {
        $this->interruptPolicy = $policy;

        return $this;
    }

    public function interruptPolicy(): ?InterruptPolicy
    {
        return $this->interruptPolicy;
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

    public function sends(): array
    {
        return $this->sends;
    }

    public function meta(): array
    {
        return $this->meta;
    }
}
