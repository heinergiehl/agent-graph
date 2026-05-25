<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Events\GraphCheckpointCreated;
use Heiner\AgentGraph\Events\GraphNodeCompleted;
use Heiner\AgentGraph\Events\GraphNodeStarted;
use Heiner\AgentGraph\Events\GraphRunCompleted;
use Heiner\AgentGraph\Events\GraphRunStarted;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Illuminate\Support\Facades\Event;

it('dispatches runtime lifecycle events', function () {
    Event::fake();

    AgentGraph::define(
        StateGraph::make('events_graph')
            ->state(['answer' => 'string|null'])
            ->node('answer', EventAnswerNode::class)
            ->edge(StateGraph::START, 'answer')
            ->edge('answer', StateGraph::END)
    );

    $run = AgentGraph::graph('events_graph')->thread('events-thread')->input([])->run();

    Event::assertDispatched(GraphRunStarted::class, fn ($event) => $event->runId === $run->runId());
    Event::assertDispatched(GraphNodeStarted::class, fn ($event) => $event->nodeId === 'answer');
    Event::assertDispatched(GraphNodeCompleted::class, fn ($event) => $event->nodeId === 'answer');
    Event::assertDispatched(GraphCheckpointCreated::class, fn ($event) => $event->runId === $run->runId());
    Event::assertDispatched(GraphRunCompleted::class, fn ($event) => $event->runId === $run->runId());
});

final class EventAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'ok']);
    }
}
