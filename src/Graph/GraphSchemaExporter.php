<?php

namespace Heiner\AgentGraph\Graph;

class GraphSchemaExporter
{
    /**
     * @param  array<string, string|array>  $schema
     * @return array<string, array>
     */
    public function state(array $schema): array
    {
        $exported = [];

        foreach ($schema as $channel => $definition) {
            $exported[$channel] = $this->channel($definition);
        }

        return $exported;
    }

    public function channel(string|array $definition): array
    {
        if (is_array($definition)) {
            return $this->structured($definition);
        }

        $types = array_values(array_filter(
            array_map('trim', explode('|', $definition)),
            fn (string $type): bool => $type !== '',
        ));

        if ($types === []) {
            $types = ['mixed'];
        }

        $nullable = in_array('null', $types, true);
        $nonNull = array_values(array_filter($types, fn (string $type): bool => $type !== 'null'));

        if (count($nonNull) === 1 && $nonNull[0] === 'messages') {
            return $this->messages($nullable);
        }

        $normalized = array_values(array_unique(array_map(
            fn (string $type): string => $this->canonicalType($type),
            $nonNull,
        )));

        if ($normalized === [] || in_array('mixed', $normalized, true)) {
            return [
                'type' => 'mixed',
                'nullable' => true,
            ];
        }

        return [
            'type' => count($normalized) === 1 ? $normalized[0] : $normalized,
            'nullable' => $nullable,
        ];
    }

    protected function structured(array $definition): array
    {
        $type = $this->canonicalType((string) ($definition['type'] ?? 'mixed'));
        $nullable = (bool) ($definition['nullable'] ?? false);

        if ($type === 'mixed') {
            return [
                'type' => 'mixed',
                'nullable' => true,
            ];
        }

        if ($type === 'messages') {
            return $this->messages($nullable);
        }

        $normalized = [
            'type' => $type,
            'nullable' => $nullable,
        ];

        if ($type === 'enum') {
            $normalized['values'] = array_values((array) ($definition['values'] ?? []));
        }

        if ($type === 'array') {
            $normalized['items'] = $this->channel($definition['items'] ?? 'mixed');
        }

        if ($type === 'object') {
            $properties = [];

            foreach ((array) ($definition['properties'] ?? []) as $property => $propertyDefinition) {
                $properties[$property] = $this->channel($propertyDefinition);
            }

            $normalized['properties'] = $properties;
        }

        return $normalized;
    }

    protected function messages(bool $nullable): array
    {
        return [
            'type' => 'array',
            'format' => 'messages',
            'nullable' => $nullable,
            'items' => [
                'type' => 'mixed',
                'nullable' => true,
            ],
        ];
    }

    protected function canonicalType(string $type): string
    {
        return match ($type) {
            'int' => 'integer',
            'float', 'double' => 'number',
            'bool' => 'boolean',
            default => $type,
        };
    }
}
