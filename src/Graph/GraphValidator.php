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
        $this->validateConditionalRouting($graph, $report);
        $this->validateReachability($graph, $report);
        $this->validateTerminalPaths($graph, $report);

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

    protected function validateConditionalRouting(GraphDefinition $graph, GraphValidationReport $report): void
    {
        foreach ($graph->conditionals() as $node => $conditional) {
            if (! array_key_exists('default', $conditional->routes)) {
                $report->warning('conditional_without_default', "Conditional node [{$node}] has no default route.", [
                    'node' => $node,
                ]);
            }

            if (($graph->edges()[$node] ?? []) !== []) {
                $report->warning('mixed_static_conditional_outgoing', "Node [{$node}] has both static and conditional outgoing routes; conditional routes take precedence at runtime.", [
                    'node' => $node,
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

    protected function validateTerminalPaths(GraphDefinition $graph, GraphValidationReport $report): void
    {
        $reachable = $this->reachableNodes($graph);

        foreach (array_keys($graph->nodes()) as $node) {
            if (! isset($reachable[$node])) {
                continue;
            }

            if (isset($graph->conditionals()[$node])) {
                continue;
            }

            if (($graph->edges()[$node] ?? []) === []) {
                $report->warning('terminal_path', "Reachable node [{$node}] has no explicit outgoing route to __end__ or another node.", [
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
