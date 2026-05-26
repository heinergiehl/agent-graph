<?php

namespace Heiner\AgentGraph\Graph;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class InterruptPolicy
{
    public function __construct(
        protected ?DateTimeInterface $expiresAt = null,
        protected ?string $resolver = null,
        protected array $meta = [],
    ) {}

    public static function expiresAt(DateTimeInterface|string $expiresAt): self
    {
        return new self($expiresAt instanceof DateTimeInterface ? $expiresAt : CarbonImmutable::parse($expiresAt));
    }

    public static function expiresAfter(int $seconds): self
    {
        return new self(now()->addSeconds($seconds));
    }

    public function resolver(string $resolver): self
    {
        return new self($this->expiresAt, $resolver, $this->meta);
    }

    public function withMeta(array $meta): self
    {
        return new self($this->expiresAt, $this->resolver, array_merge($this->meta, $meta));
    }

    public function expiresAtValue(): ?DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function resolverValue(): ?string
    {
        return $this->resolver;
    }

    public function meta(): array
    {
        return $this->meta;
    }
}
