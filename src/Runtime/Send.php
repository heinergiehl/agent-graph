<?php

namespace Heiner\AgentGraph\Runtime;

use InvalidArgumentException;

class Send
{
    protected function __construct(
        protected string $node,
        protected array $input = [],
        protected array $meta = [],
    ) {}

    public static function to(string $node, array $input = [], array $meta = []): self
    {
        if ($node === '') {
            throw new InvalidArgumentException('Send target node cannot be empty.');
        }

        return new self($node, $input, $meta);
    }

    public static function from(mixed $send): self
    {
        if ($send instanceof self) {
            return $send;
        }

        if (is_string($send)) {
            return self::to($send);
        }

        if (is_array($send)) {
            $node = $send['node'] ?? null;

            if (! is_string($node) || $node === '') {
                throw new InvalidArgumentException('Send array requires a non-empty node value.');
            }

            return self::to(
                $node,
                is_array($send['input'] ?? null) ? $send['input'] : [],
                is_array($send['meta'] ?? null) ? $send['meta'] : [],
            );
        }

        throw new InvalidArgumentException('Send values must be Send instances, node strings, or send arrays.');
    }

    public function node(): string
    {
        return $this->node;
    }

    public function input(): array
    {
        return $this->input;
    }

    public function meta(): array
    {
        return $this->meta;
    }

    public function toArray(): array
    {
        return [
            'node' => $this->node,
            'input' => $this->input,
            'meta' => $this->meta,
        ];
    }
}
