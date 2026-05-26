<?php

namespace Heiner\AgentGraph\Runtime;

class StateDiff
{
    public function __construct(
        protected array $added = [],
        protected array $changed = [],
        protected array $removed = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function added(): array
    {
        return $this->added;
    }

    public function changed(): array
    {
        return $this->changed;
    }

    public function removed(): array
    {
        return $this->removed;
    }

    public function toArray(): array
    {
        return [
            'added' => $this->added,
            'changed' => $this->changed,
            'removed' => $this->removed,
        ];
    }
}
