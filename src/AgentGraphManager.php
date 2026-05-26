<?php

namespace Heiner\AgentGraph;

use Heiner\AgentGraph\Graph\GraphDefinition;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\LaravelAi\GraphTool;
use Heiner\AgentGraph\Runtime\CheckpointSnapshot;
use Heiner\AgentGraph\Runtime\GraphRuntime;
use Heiner\AgentGraph\Runtime\PendingGraphRun;
use Heiner\AgentGraph\Runtime\RunEventDispatcher;
use Heiner\AgentGraph\Runtime\RunResult;
use Heiner\AgentGraph\Runtime\RunSnapshot;
use Heiner\AgentGraph\Runtime\RunTimeline;
use InvalidArgumentException;

class AgentGraphManager
{
    /** @var array<string, GraphDefinition> */
    protected array $graphs = [];

    public function __construct(
        protected GraphRuntime $runtime,
        protected ?RunEventDispatcher $events = null,
    ) {}

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

    public function run(GraphDefinition $graph, string $threadId, array $input = [], array $meta = [], ?callable $onEvent = null, bool $collectEvents = false): RunResult
    {
        return $this->observe($onEvent, $collectEvents, fn (): RunResult => $this->runtime->run($graph, $threadId, $input, $meta));
    }

    public function resume(string $runId, array $payload = [], ?callable $onEvent = null, bool $collectEvents = false): RunResult
    {
        return $this->observe(
            $onEvent,
            $collectEvents,
            fn (): RunResult => $this->runtime->resume($runId, $payload, $this->graphs),
            $runId,
        );
    }

    public function resumeWithStateEdit(string $runId, string $interruptId, array $statePatch, ?string $resolvedBy = null, ?callable $onEvent = null, bool $collectEvents = false): RunResult
    {
        return $this->observe(
            $onEvent,
            $collectEvents,
            fn (): RunResult => $this->runtime->resumeWithStateEdit($runId, $interruptId, $statePatch, $this->graphs, $resolvedBy),
            $runId,
        );
    }

    public function cancel(string $runId, array $meta = []): RunResult
    {
        return $this->runtime->cancel($runId, $meta);
    }

    public function checkpoint(string $checkpointId, bool $withWrites = false): ?CheckpointSnapshot
    {
        return $this->runtime->checkpoint($checkpointId, $withWrites);
    }

    public function replay(string $checkpointId, ?string $threadId = null, array $meta = [], ?callable $onEvent = null, bool $collectEvents = false): RunResult
    {
        return $this->observe($onEvent, $collectEvents, fn (): RunResult => $this->runtime->replay($checkpointId, $this->graphs, $threadId, $meta));
    }

    public function fork(string $checkpointId, array $statePatch = [], ?string $threadId = null, ?string $asNode = null, array $meta = [], ?callable $onEvent = null, bool $collectEvents = false): RunResult
    {
        return $this->observe($onEvent, $collectEvents, fn (): RunResult => $this->runtime->fork($checkpointId, $statePatch, $this->graphs, $threadId, $asNode, $meta));
    }

    public function inspect(string $runId, bool $withHistory = false, bool $withTraces = false): ?RunSnapshot
    {
        return $this->runtime->inspect($runId, $withHistory, $withTraces);
    }

    public function timeline(string $runId, bool $includeState = false, bool $includeDiff = true): ?RunTimeline
    {
        return $this->runtime->timeline($runId, $includeState, $includeDiff);
    }

    public function runs(array $filters = [], int $limit = 50): array
    {
        return $this->runtime->runs($filters, $limit);
    }

    public function tasks(array $filters = [], int $limit = 50): array
    {
        return $this->runtime->tasks($filters, $limit);
    }

    public function timeTravelChildren(string $checkpointId, int $limit = 50): array
    {
        return $this->runtime->timeTravelChildren($checkpointId, $limit);
    }

    public function tool(string $graphKey): GraphTool
    {
        return new GraphTool($this, $graphKey);
    }

    protected function observe(?callable $onEvent, bool $collectEvents, callable $callback, ?string $runId = null): RunResult
    {
        $observed = $this->events()->observe($onEvent, $collectEvents, $callback, $runId);

        return $observed['result']->withEvents($observed['events']);
    }

    protected function events(): RunEventDispatcher
    {
        return $this->events ??= app(RunEventDispatcher::class);
    }
}
