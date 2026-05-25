<?php

namespace Heiner\AgentGraph\Graph;

use InvalidArgumentException;

class GraphDefinition
{
    public function __construct(
        protected string $key,
        protected string $version,
        protected array $schema,
        protected array $nodes,
        protected array $edges,
        protected array $conditionals,
        protected array $reducers = [],
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function schema(): array
    {
        return $this->schema;
    }

    public function reducers(): array
    {
        return $this->reducers;
    }

    public function node(string $id): callable|string
    {
        if (! isset($this->nodes[$id])) {
            throw new InvalidArgumentException("Unknown node [{$id}].");
        }

        return $this->nodes[$id];
    }

    public function nodes(): array
    {
        return $this->nodes;
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function hasEndpoint(string $id): bool
    {
        return $this->isKnownEndpoint($id);
    }

    public function entryNode(): string
    {
        return $this->edges[StateGraph::START][0] ?? throw new InvalidArgumentException('Graph has no entry node.');
    }

    public function successorsOf(string $id, array $state): array
    {
        if ($id === StateGraph::START) {
            return [$this->entryNode()];
        }

        if ($id === StateGraph::END) {
            return [StateGraph::END];
        }

        return $this->resolveNext($id, $state);
    }

    public function resolveNext(string $nodeId, array $state): array
    {
        if (isset($this->conditionals[$nodeId])) {
            return $this->conditionals[$nodeId]->resolve($state);
        }

        return $this->edges[$nodeId] ?? [];
    }

    public function validate(): void
    {
        if (! isset($this->edges[StateGraph::START]) || $this->edges[StateGraph::START] === []) {
            throw new InvalidArgumentException('Graph must define an edge from __start__.');
        }

        foreach ($this->edges as $from => $targets) {
            if (! $this->isKnownEndpoint($from)) {
                throw new InvalidArgumentException("Unknown edge source [{$from}].");
            }

            foreach ($targets as $target) {
                if (! $this->isKnownEndpoint($target)) {
                    throw new InvalidArgumentException("Unknown edge target [{$target}].");
                }
            }
        }

        foreach ($this->conditionals as $from => $conditional) {
            if (! isset($this->nodes[$from])) {
                throw new InvalidArgumentException("Unknown conditional source [{$from}].");
            }

            foreach ($conditional->routes as $target) {
                foreach ((array) $target as $node) {
                    if (! $this->isKnownEndpoint($node)) {
                        throw new InvalidArgumentException("Unknown conditional target [{$node}].");
                    }
                }
            }
        }
    }

    protected function isKnownEndpoint(string $node): bool
    {
        return $node === StateGraph::START || $node === StateGraph::END || isset($this->nodes[$node]);
    }
}
