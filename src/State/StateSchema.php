<?php

namespace Heiner\AgentGraph\State;

class StateSchema
{
    protected array $channels = [];

    public static function make(): self
    {
        return new self;
    }

    public function string(string $key): self
    {
        return $this->channel($key, 'string');
    }

    public function integer(string $key): self
    {
        return $this->channel($key, 'int');
    }

    public function boolean(string $key): self
    {
        return $this->channel($key, 'bool');
    }

    public function array(string $key, string|array $items = 'mixed'): self
    {
        return $this->channel($key, ['type' => 'array', 'items' => $items]);
    }

    public function object(string $key, array $properties = []): self
    {
        return $this->channel($key, ['type' => 'object', 'properties' => $properties]);
    }

    public function enum(string $key, array $values): self
    {
        return $this->channel($key, ['type' => 'enum', 'values' => $values]);
    }

    public function messages(string $key): self
    {
        return $this->channel($key, 'messages');
    }

    public function channel(string $key, string|array $definition): self
    {
        $this->channels[$key] = $definition;

        return $this;
    }

    public function toArray(): array
    {
        return $this->channels;
    }
}
