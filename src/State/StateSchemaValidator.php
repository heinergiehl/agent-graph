<?php

namespace Heiner\AgentGraph\State;

use InvalidArgumentException;

class StateSchemaValidator
{
    private const PRIMITIVE_TYPES = [
        'mixed',
        'null',
        'string',
        'int',
        'integer',
        'float',
        'double',
        'bool',
        'boolean',
        'array',
        'messages',
        'object',
    ];

    private const STRUCTURED_TYPES = [
        'mixed',
        'enum',
        'array',
        'object',
    ];

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
        $this->assertKnownTypeExpression($typeExpression);

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
            default => false,
        };
    }

    protected function matchesStructured(array $schema, mixed $value): bool
    {
        return match ($schema['type'] ?? 'mixed') {
            'mixed' => true,
            'enum' => in_array($value, (array) ($schema['values'] ?? []), true),
            'array' => is_array($value) && array_is_list($value) && $this->arrayItemsMatch($schema['items'] ?? 'mixed', $value),
            'object' => is_array($value) && $this->objectMatches((array) ($schema['properties'] ?? []), $value),
            default => false,
        };
    }

    protected function arrayItemsMatch(string|array $items, array $value): bool
    {
        foreach ($value as $item) {
            if (! $this->matches($items, $item)) {
                return false;
            }
        }

        return true;
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

    protected function assertKnownTypeExpression(string|array $typeExpression): void
    {
        if (is_array($typeExpression)) {
            $this->assertKnownStructuredType($typeExpression);

            return;
        }

        foreach (explode('|', $typeExpression) as $type) {
            $type = trim($type);

            if (! in_array($type, self::PRIMITIVE_TYPES, true)) {
                throw new InvalidArgumentException("Unknown state schema type [{$type}].");
            }
        }
    }

    protected function assertKnownStructuredType(array $schema): void
    {
        $type = $schema['type'] ?? 'mixed';

        if (! is_string($type) || ! in_array($type, self::STRUCTURED_TYPES, true)) {
            $label = is_scalar($type) ? (string) $type : get_debug_type($type);

            throw new InvalidArgumentException("Unknown structured state schema type [{$label}].");
        }

        if ($type === 'array' && array_key_exists('items', $schema)) {
            $this->assertKnownTypeExpression($schema['items']);
        }

        if ($type === 'object') {
            foreach ((array) ($schema['properties'] ?? []) as $propertyType) {
                $this->assertKnownTypeExpression($propertyType);
            }
        }
    }
}
