<?php

namespace Heiner\AgentGraph\State;

use Closure;

class Reducer
{
    public function __construct(
        protected string $name,
        protected Closure $reducer,
    ) {}

    public static function lastWriteWins(): self
    {
        return new self('last_write_wins', fn (mixed $current, mixed $incoming): mixed => $incoming);
    }

    public static function append(): self
    {
        return new self('append', function (mixed $current, mixed $incoming): array {
            return array_values(array_merge((array) ($current ?? []), (array) ($incoming ?? [])));
        });
    }

    public static function merge(): self
    {
        return new self('merge', fn (mixed $current, mixed $incoming): array => array_merge((array) ($current ?? []), (array) ($incoming ?? [])));
    }

    public static function addMessages(): self
    {
        return new self('add_messages', function (mixed $current, mixed $incoming): array {
            return array_values(array_merge((array) ($current ?? []), (array) ($incoming ?? [])));
        });
    }

    public static function maxConfidence(): self
    {
        return new self('max_confidence', fn (mixed $current, mixed $incoming): float|int => max($current ?? 0, $incoming ?? 0));
    }

    public static function custom(Closure $reducer): self
    {
        return new self('custom', $reducer);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function apply(mixed $current, mixed $incoming): mixed
    {
        return ($this->reducer)($current, $incoming);
    }
}
