<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('cancels a durable run and persists the cancelled status', function () {
    AgentGraph::define(
        StateGraph::make('cancel_graph')
            ->state(['approved' => 'bool|null'])
            ->node('approval', CancelApprovalNode::class)
            ->edge(StateGraph::START, 'approval')
            ->edge('approval', StateGraph::END)
    );

    $run = AgentGraph::graph('cancel_graph')->thread('cancel-thread')->input([])->run();
    $cancelled = AgentGraph::cancel($run->runId());

    expect($cancelled->cancelled())->toBeTrue()
        ->and(app('agent-graph.runs')->find($run->runId())['status'])->toBe('cancelled');
});

final class CancelApprovalNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::interrupt('approval', ['title' => 'Approve']);
    }
}
