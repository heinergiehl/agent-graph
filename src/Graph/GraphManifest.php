<?php

namespace Heiner\AgentGraph\Graph;

use Closure;
use Heiner\AgentGraph\State\Reducer;
use InvalidArgumentException;

class GraphManifest
{
    public function __construct(protected GraphDefinition $graph) {}

    public function toArray(int $version = 2): array
    {
        return match ($version) {
            1 => $this->toArrayV1(),
            2 => $this->toArrayV2(),
            default => throw new InvalidArgumentException("Unsupported graph manifest version [{$version}]."),
        };
    }

    protected function toArrayV2(): array
    {
        return [
            'manifest_version' => 2,
            'key' => $this->graph->key(),
            'version' => $this->graph->version(),
            'state' => $this->state(),
            'reducers' => $this->reducers(),
            'nodes' => $this->nodesV2(),
            'edges' => $this->graph->edges(),
            'conditionals' => $this->conditionals(),
            'policies' => $this->policies(),
        ];
    }

    protected function toArrayV1(): array
    {
        return [
            'key' => $this->graph->key(),
            'version' => $this->graph->version(),
            'state' => $this->stateV1(),
            'reducers' => $this->reducers(),
            'nodes' => $this->nodesV1(),
            'edges' => $this->graph->edges(),
            'conditionals' => $this->conditionals(),
            'policies' => $this->policies(),
        ];
    }

    protected function state(): array
    {
        return (new GraphSchemaExporter)->state($this->graph->schema());
    }

    protected function stateV1(): array
    {
        $state = [];

        foreach ($this->graph->schema() as $channel => $definition) {
            $state[$channel] = $this->normalizeSchemaV1($definition);
        }

        return $state;
    }

    protected function normalizeSchemaV1(string|array $definition): array
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
                $normalized['items'] = $this->normalizeSchemaV1($definition['items'] ?? 'mixed');
            }

            if ($type === 'object') {
                $properties = [];

                foreach ((array) ($definition['properties'] ?? []) as $property => $propertyDefinition) {
                    $properties[$property] = $this->normalizeSchemaV1($propertyDefinition);
                }

                $normalized['properties'] = $properties;
            }

            return $normalized;
        }

        $types = array_values(array_filter(array_map('trim', explode('|', $definition)), fn (string $type): bool => $type !== ''));
        $nullable = in_array('null', $types, true);
        $nonNull = array_values(array_filter($types, fn (string $type): bool => $type !== 'null'));
        $type = count($nonNull) > 1 ? $nonNull : ($nonNull[0] ?? 'mixed');

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
            return $reducer->name();
        }

        if ($reducer instanceof Closure) {
            return 'custom';
        }

        return get_debug_type($reducer);
    }

    protected function nodesV2(): array
    {
        $nodes = [];

        foreach (array_keys($this->graph->nodes()) as $id) {
            $nodes[$id] = [
                'id' => $id,
                'metadata' => $this->graph->nodeMetadata($id),
                'input_channels' => $this->graph->nodeInputChannels($id),
                'output_channels' => $this->graph->nodeOutputChannels($id),
                'can_interrupt' => $this->graph->nodeCanInterrupt($id),
                'side_effects' => $this->graph->nodeSideEffects($id),
            ];
        }

        return $nodes;
    }

    protected function nodesV1(): array
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
