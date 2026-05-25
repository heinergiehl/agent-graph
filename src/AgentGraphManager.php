<?php

namespace Heiner\AgentGraph;

use Heiner\AgentGraph\Graph\GraphDefinition;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\LaravelAi\GraphTool;
use Heiner\AgentGraph\Runtime\GraphRuntime;
use Heiner\AgentGraph\Runtime\PendingGraphRun;
use Heiner\AgentGraph\Runtime\RunResult;
use InvalidArgumentException;

class AgentGraphManager
{
    /** @var array<string, GraphDefinition> */
    protected array $graphs = [];

    public function __construct(protected GraphRuntime $runtime) {}

    public function define(StateGraph|GraphDefinition $graph): GraphDefinition
    {
        $definition = $graph instanceof StateGraph ? $graph->compile() : $graph;
        $this->graphs[$definition->key()] = $definition;

        return $definition;
    }

    public function graph(string $key): PendingGraphRun
    {
        return new PendingGraphRun($this, $this->definition($key));
    }

    public function definition(string $key): GraphDefinition
    {
        return $this->graphs[$key] ?? throw new InvalidArgumentException("Graph [{$key}] is not defined.");
    }

    public function run(GraphDefinition $graph, string $threadId, array $input = [], array $meta = []): RunResult
    {
        return $this->runtime->run($graph, $threadId, $input, $meta);
    }

    public function resume(string $runId, array $payload = []): RunResult
    {
        return $this->runtime->resume($runId, $payload, $this->graphs);
    }

    public function cancel(string $runId, array $meta = []): RunResult
    {
        return $this->runtime->cancel($runId, $meta);
    }

    public function tool(string $graphKey): GraphTool
    {
        return new GraphTool($this, $graphKey);
    }
}
