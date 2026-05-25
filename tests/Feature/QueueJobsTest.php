<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Queue\ResumeGraphJob;
use Heiner\AgentGraph\Queue\RunGraphJob;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('runs and resumes graphs through queue job handlers', function () {
    AgentGraph::define(
        StateGraph::make('queued_graph')
            ->state(['input' => 'string|null', 'approved' => 'bool|null', 'answer' => 'string|null'])
            ->node('approval', QueueApprovalNode::class)
            ->node('answer', QueueAnswerNode::class)
            ->edge(StateGraph::START, 'approval')
            ->edge('approval', 'answer')
            ->edge('answer', StateGraph::END)
    );

    $interrupted = (new RunGraphJob('queued_graph', 'queue-thread', ['input' => 'deploy']))->handle(app('agent-graph'));

    expect($interrupted->interrupted())->toBeTrue();

    $completed = (new ResumeGraphJob($interrupted->runId(), [
        'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
        'approved' => true,
    ]))->handle(app('agent-graph'));

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('answer'))->toBe('Approved deploy');
});

final class QueueApprovalNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->state('approved') !== true) {
            return NodeResult::interrupt('approval', ['title' => 'Approve deployment']);
        }

        return NodeResult::write([]);
    }
}

final class QueueAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'Approved '.$context->state('input')]);
    }
}
