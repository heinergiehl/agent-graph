<?php

use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('executes long synchronous graphs without growing the PHP call stack per step', function () {
    $depths = [];
    AgentGraph::define(StateGraph::make('iterative_loop')
        ->state(['count' => 'int'])
        ->node('work', function (NodeContext $context) use (&$depths) {
            $depths[] = count(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
            $count = $context->state('count', 0) + 1;

            return $count === 80 ? NodeResult::end(['count' => $count]) : NodeResult::write(['count' => $count]);
        })->edge(StateGraph::START, 'work')->edge('work', 'work'));
    $result = AgentGraph::graph('iterative_loop')->run();
    expect($result->completed())->toBeTrue()->and($result->state('count'))->toBe(80)
        ->and(max($depths) - min($depths))->toBeLessThan(5);
});
