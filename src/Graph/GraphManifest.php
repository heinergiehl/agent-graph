<?php

namespace Heiner\AgentGraph\Graph;

use Closure;
use Heiner\AgentGraph\State\Reducer;

class GraphManifest
{
    public function __construct(protected GraphDefinition $graph) {}

    public function toArray(): array
    {
        return [
            'key' => $this->graph->key(),
            'version' => $this->graph->version(),
            'state' => $this->state(),
            'reducers' => $this->reducers(),
            'nodes' => $this->nodes(),
            'edges' => $this->graph->edges(),
            'conditionals' => $this->conditionals(),
            'policies' => $this->policies(),
        ];
    }

    protected function state(): array
    {
        $state = [];

        foreach ($this->graph->schema() as $channel => $definition) {
            $state[$channel] = $this->normalizeSchema($definition);
        }

        return $state;
    }

    protected function normalizeSchema(string|array $definition): array
    {
        if (is_array($definition)) {
            $type = (string) ($definition['type'] ?? 'mixed');
            $normalized = [
                'type' => $type,
                'nullable' => (bool) ($definition['nullable'] ?? false),
            ];

            if ($type === 'enum') {
                $normalized['values'] = array_values((array) ($definition['values'] ?? []));
            }

            if ($type === 'array') {
                $normalized['items'] = $this->normalizeSchema($definition['items'] ?? 'mixed');
            }

            if ($type === 'object') {
                $properties = [];

                foreach ((array) ($definition['properties'] ?? []) as $property => $propertyDefinition) {
                    $properties[$property] = $this->normalizeSchema($propertyDefinition);
                }

                $normalized['properties'] = $properties;
            }

            return $normalized;
        }

        $types = array_values(array_filter(array_map('trim', explode('|', $definition)), fn (string $type): bool => $type !== ''));
        $nullable = in_array('null', $types, true);
        $nonNull = array_values(array_filter($types, fn (string $type): bool => $type !== 'null'));
        $type = $nonNull[0] ?? 'mixed';

        return [
            'type' => $type,
            'nullable' => $nullable,
        ];
    }

    protected function reducers(): array
    {
        $reducers = [];

        foreach ($this->graph->reducers() as $channel => $reducer) {
            $reducers[$channel] = $this->reducerName($reducer);
        }

        return $reducers;
    }

    protected function reducerName(mixed $reducer): string
    {
        if (is_string($reducer)) {
            return $reducer;
        }

        if ($reducer instanceof Reducer) {
            return 'custom';
        }

        if ($reducer instanceof Closure) {
            return 'custom';
        }

        return get_debug_type($reducer);
    }

    protected function nodes(): array
    {
        $nodes = [];

        foreach ($this->graph->nodes() as $id => $node) {
            $nodes[$id] = [
                'id' => $id,
                'class' => is_string($node) ? $node : null,
                'callable' => ! is_string($node),
            ];
        }

        return $nodes;
    }

    protected function conditionals(): array
    {
        $conditionals = [];

        foreach ($this->graph->conditionals() as $node => $conditional) {
            $conditionals[$node] = [
                'routes' => $conditional->routes,
            ];
        }

        return $conditionals;
    }

    protected function policies(): array
    {
        $policies = [];

        foreach ($this->graph->nodePolicies() as $node => $policy) {
            $entry = [];

            if ($retry = $policy->retryPolicy()) {
                $entry['retry'] = [
                    'max_attempts' => $retry->maxAttempts(),
                    'delay_ms' => $retry->delayMs(),
                    'backoff' => $retry->backoff(),
                    'max_delay_ms' => $retry->maxDelayMs(),
                ];
            }

            if ($timeout = $policy->timeoutPolicy()) {
                $entry['timeout'] = [
                    'seconds' => $timeout->seconds(),
                ];
            }

            if ($concurrency = $policy->concurrencyPolicy()) {
                $entry['concurrency'] = [
                    'limit' => $concurrency->limit(),
                    'key' => $concurrency->key(),
                ];
            }

            if ($entry !== []) {
                $policies[$node] = $entry;
            }
        }

        return $policies;
    }
}
