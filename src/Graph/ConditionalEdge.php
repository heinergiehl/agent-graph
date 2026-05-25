<?php

namespace Heiner\AgentGraph\Graph;

use Closure;

class ConditionalEdge
{
    public function __construct(
        public readonly Closure $resolver,
        public readonly array $routes,
    ) {}

    public function resolve(array $state): array
    {
        $route = ($this->resolver)($state);

        if (is_array($route)) {
            return array_values(array_map(fn ($name) => $this->routes[$name] ?? $name, $route));
        }

        $target = $this->routes[$route] ?? $this->routes['default'] ?? $route;

        return is_array($target) ? array_values($target) : [$target];
    }
}
