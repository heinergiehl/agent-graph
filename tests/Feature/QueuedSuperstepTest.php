<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('keeps queued superstep mode opt in and records node execution rows while preserving sync results', function () {
    config()->set('agent-graph.execution.mode', 'queued_supersteps');

    AgentGraph::define(
        StateGraph::make('queued_superstep_graph')
            ->state(['items' => 'array'])
            ->reducer('items', fn (array $current, array $next): array => array_merge($current, $next))
            ->node('fanout', QueuedFanoutNode::class)
            ->node('a', QueuedBranchANode::class)
            ->node('b', QueuedBranchBNode::class)
            ->edge('__start__', 'fanout')
            ->edge('fanout', 'a')
            ->edge('fanout', 'b')
            ->compile(),
    );

    $run = AgentGraph::graph('queued_superstep_graph')->thread('queued-superstep-thread')->input(['items' => []])->run();

    expect($run->status())->toBe('completed')
        ->and($run->state('items'))->toBe(['a', 'b'])
        ->and(AgentGraph::nodeExecutions($run->runId()))->toHaveCount(3);
});

final class QueuedFanoutNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([]);
    }
}

final class QueuedBranchANode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['items' => ['a']]);
    }
}

final class QueuedBranchBNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['items' => ['b']]);
    }
}
