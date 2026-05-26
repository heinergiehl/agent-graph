<?php

namespace Heiner\AgentGraph\Graph;

use Closure;
use Heiner\AgentGraph\State\StateSchema;
use InvalidArgumentException;

class StateGraph
{
    public const START = '__start__';

    public const END = '__end__';

    protected array $schema = [];

    protected array $nodes = [];

    protected array $edges = [];

    protected array $conditionals = [];

    protected array $reducers = [];

    protected array $nodePolicies = [];

    protected function __construct(protected string $key, protected string $version = '1') {}

    public static function make(string $key, string $version = '1'): self
    {
        return new self($key, $version);
    }

    public function state(array|StateSchema $schema): self
    {
        $this->schema = $schema instanceof StateSchema ? $schema->toArray() : $schema;

        return $this;
    }

    public function reducer(string $channel, mixed $reducer): self
    {
        $this->reducers[$channel] = $reducer;

        return $this;
    }

    public function node(string $id, callable|string $node): self
    {
        if (isset($this->nodes[$id])) {
            throw new InvalidArgumentException("Node [{$id}] already exists.");
        }

        if (in_array($id, [self::START, self::END], true)) {
            throw new InvalidArgumentException("Node [{$id}] is reserved.");
        }

        $this->nodes[$id] = $node;

        return $this;
    }

    public function edge(string $from, string $to): self
    {
        $this->edges[$from] ??= [];
        $this->edges[$from][] = $to;

        return $this;
    }

    public function conditional(string $from, Closure $resolver, array $routes): self
    {
        $this->conditionals[$from] = new ConditionalEdge($resolver, $routes);

        return $this;
    }

    public function retry(string $nodeId, int $maxAttempts = 3, int $delayMs = 0, float $backoff = 1.0, ?int $maxDelayMs = null, ?callable $when = null): self
    {
        $policy = $this->nodePolicies[$nodeId] ?? NodePolicy::default();
        $this->nodePolicies[$nodeId] = $policy->withRetryPolicy(new RetryPolicy(
            maxAttempts: $maxAttempts,
            delayMs: $delayMs,
            backoff: $backoff,
            maxDelayMs: $maxDelayMs,
            when: $when,
        ));

        return $this;
    }

    public function timeout(string $nodeId, float $seconds): self
    {
        $policy = $this->nodePolicies[$nodeId] ?? NodePolicy::default();
        $this->nodePolicies[$nodeId] = $policy->withTimeoutPolicy(new TimeoutPolicy($seconds));

        return $this;
    }

    public function concurrency(string $nodeId, int $limit = 1, ?string $key = null): self
    {
        $policy = $this->nodePolicies[$nodeId] ?? NodePolicy::default();
        $this->nodePolicies[$nodeId] = $policy->withConcurrencyPolicy(new ConcurrencyPolicy($limit, $key));

        return $this;
    }

    public function compile(): GraphDefinition
    {
        $definition = new GraphDefinition(
            key: $this->key,
            version: $this->version,
            schema: $this->schema,
            nodes: $this->nodes,
            edges: $this->edges,
            conditionals: $this->conditionals,
            reducers: $this->reducers,
            nodePolicies: $this->nodePolicies,
        );

        $definition->validate();

        return $definition;
    }
}
