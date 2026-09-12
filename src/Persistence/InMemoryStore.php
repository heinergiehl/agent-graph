<?php

namespace Heiner\AgentGraph\Persistence;

/** @internal Transaction snapshots for the process-local testing driver. */
abstract class InMemoryStore
{
    public function snapshot(): array
    {
        return get_object_vars($this);
    }

    public function restore(array $snapshot): void
    {
        foreach ($snapshot as $property => $value) {
            $this->{$property} = $value;
        }
    }
}
