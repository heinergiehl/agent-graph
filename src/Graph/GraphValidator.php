<?php

namespace Heiner\AgentGraph\Graph;

use Heiner\AgentGraph\State\StateReducer;
use Heiner\AgentGraph\State\StateSchemaValidator;
use InvalidArgumentException;
use Throwable;

class GraphValidator
{
    public static function validate(GraphDefinition $graph): GraphValidationReport
    {
        return (new self)->inspect($graph);
    }

    public function inspect(GraphDefinition $graph): GraphValidationReport
    {
        $report = GraphValidationReport::make();

        $this->validateStateSchema($graph, $report);
        $this->validateReducers($graph, $report);
        $this->validateReachability($graph, $report);

        return $report;
    }

    protected function validateStateSchema(GraphDefinition $graph, GraphValidationReport $report): void
    {
        $validator = new StateSchemaValidator;

        foreach ($graph->schema() as $channel => $definition) {
            try {
                $validator->matches($definition, null);
            } catch (InvalidArgumentException $exception) {
                $report->error('unknown_state_schema_type', $exception->getMessage(), [
                    'channel' => $channel,
                ]);
            }
        }
    }

    protected function validateReducers(GraphDefinition $graph, GraphValidationReport $report): void
    {
        foreach ($graph->reducers() as $channel => $reducer) {
            try {
                new StateReducer([$channel => $reducer]);
            } catch (Throwable $exception) {
                $report->error('unknown_reducer', $exception->getMessage(), [
                    'channel' => $channel,
                ]);
            }
        }
    }

    protected function validateReachability(GraphDefinition $graph, GraphValidationReport $report): void
    {
        $reachable = $this->reachableNodes($graph);

        foreach (array_keys($graph->nodes()) as $node) {
            if (! isset($reachable[$node])) {
                $report->warning('unreachable_node', "Node [{$node}] is not reachable from __start__.", [
                    'node' => $node,
                ]);
            }
        }
    }

    protected function reachableNodes(GraphDefinition $graph): array
    {
        $reachable = [];
        $queue = $graph->entryNodes();

        while ($queue !== []) {
            $node = array_shift($queue);

            if ($node === StateGraph::END || isset($reachable[$node])) {
                continue;
            }

            $reachable[$node] = true;

            if (isset($graph->conditionals()[$node])) {
                foreach ($graph->conditionals()[$node]->routes as $target) {
                    foreach ((array) $target as $routeTarget) {
                        $queue[] = $routeTarget;
                    }
                }

                continue;
            }

            foreach ($graph->edges()[$node] ?? [] as $target) {
                $queue[] = $target;
            }
        }

        return $reachable;
    }
}
