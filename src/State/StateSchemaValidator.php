<?php

namespace Heiner\AgentGraph\State;

use InvalidArgumentException;

class StateSchemaValidator
{
    public function assertPatch(array $schema, array $patch, bool $strictKeys = true): void
    {
        foreach ($patch as $key => $value) {
            if (! array_key_exists($key, $schema)) {
                if ($strictKeys) {
                    throw new InvalidArgumentException("State patch contains unknown state key [{$key}].");
                }

                continue;
            }

            $type = (string) $schema[$key];

            if (! $this->matches($type, $value)) {
                throw new InvalidArgumentException("State value [{$key}] must match schema type [{$type}].");
            }
        }
    }

    public function matches(string $typeExpression, mixed $value): bool
    {
        foreach (explode('|', $typeExpression) as $type) {
            if ($this->matchesSingleType(trim($type), $value)) {
                return true;
            }
        }

        return false;
    }

    protected function matchesSingleType(string $type, mixed $value): bool
    {
        return match ($type) {
            'mixed' => true,
            'null' => $value === null,
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'float', 'double' => is_float($value) || is_int($value),
            'bool', 'boolean' => is_bool($value),
            'array', 'messages' => is_array($value),
            'object' => is_object($value),
            default => true,
        };
    }
}
