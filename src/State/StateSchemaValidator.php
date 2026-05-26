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

            $type = $schema[$key];

            if (! $this->matches($type, $value)) {
                $label = is_array($type) ? json_encode($type) : (string) $type;
                throw new InvalidArgumentException("State value [{$key}] must match schema type [{$label}].");
            }
        }
    }

    public function matches(string|array $typeExpression, mixed $value): bool
    {
        if (is_array($typeExpression)) {
            return $this->matchesStructured($typeExpression, $value);
        }

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

    protected function matchesStructured(array $schema, mixed $value): bool
    {
        return match ($schema['type'] ?? 'mixed') {
            'enum' => in_array($value, (array) ($schema['values'] ?? []), true),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && $this->objectMatches((array) ($schema['properties'] ?? []), $value),
            default => true,
        };
    }

    protected function objectMatches(array $properties, array $value): bool
    {
        foreach ($properties as $key => $type) {
            if (array_key_exists($key, $value) && ! $this->matches($type, $value[$key])) {
                return false;
            }
        }

        return true;
    }
}
