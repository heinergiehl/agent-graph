<?php

namespace Heiner\AgentGraph\Runtime;

use InvalidArgumentException;

final class RuntimeOptions
{
    public function __construct(
        public readonly ?int $maxSteps = null,
    ) {
        if ($this->maxSteps !== null && $this->maxSteps < 1) {
            throw new InvalidArgumentException('Runtime max_steps must be at least 1.');
        }
    }

    public static function from(self|array|null $options = null): self
    {
        if ($options instanceof self) {
            return $options;
        }

        return self::fromArray($options ?? []);
    }

    public static function fromArray(array $options = []): self
    {
        return new self(
            maxSteps: isset($options['max_steps']) ? (int) $options['max_steps'] : null,
        );
    }

    public static function fromRun(array $run): self
    {
        $meta = is_array($run['meta'] ?? null) ? $run['meta'] : [];
        $options = is_array($meta['runtime_options'] ?? null) ? $meta['runtime_options'] : [];

        return self::fromArray($options);
    }

    public function maxSteps(): int
    {
        return $this->maxSteps ?? (int) config('agent-graph.max_steps', 100);
    }

    public function isDefault(): bool
    {
        return $this->maxSteps === null;
    }

    public function toArray(): array
    {
        return $this->maxSteps === null ? [] : ['max_steps' => $this->maxSteps];
    }

    public function applyToMeta(array $meta): array
    {
        if ($this->isDefault()) {
            return $meta;
        }

        $existing = is_array($meta['runtime_options'] ?? null) ? $meta['runtime_options'] : [];
        $meta['runtime_options'] = array_merge($existing, $this->toArray());

        return $meta;
    }
}
