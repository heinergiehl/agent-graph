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
use Heiner\AgentGraph\Runtime\RunEvent;
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

it('observes ordered normalized run events without collecting them by default', function () {
    AgentGraph::define(
        StateGraph::make('observed_events_graph')
            ->state(['answer' => 'string|null'])
            ->node('answer', EventAnswerNode::class)
            ->edge(StateGraph::START, 'answer')
            ->edge('answer', StateGraph::END)
    );

    $events = [];

    $run = AgentGraph::graph('observed_events_graph')
        ->thread('observed-events-thread')
        ->input([])
        ->onEvent(function (RunEvent $event) use (&$events): void {
            $events[] = $event;
        })
        ->run();

    expect($run->completed())->toBeTrue()
        ->and($run->events())->toBe([])
        ->and(array_map(fn (RunEvent $event): string => $event->type(), $events))->toBe([
            'run.started',
            'node.started',
            'node.completed',
            'checkpoint.created',
            'run.completed',
        ])
        ->and($events[0]->runId())->toBe($run->runId())
        ->and($events[0]->threadId())->toBe('observed-events-thread')
        ->and($events[0]->graphKey())->toBe('observed_events_graph')
        ->and($events[1]->nodeId())->toBe('answer')
        ->and($events[3]->payload())->toHaveKey('checkpoint_id');
});

it('collects normalized run events on the result when requested', function () {
    AgentGraph::define(
        StateGraph::make('collected_events_graph')
            ->state(['answer' => 'string|null'])
            ->node('answer', EventAnswerNode::class)
            ->edge(StateGraph::START, 'answer')
            ->edge('answer', StateGraph::END)
    );

    $run = AgentGraph::graph('collected_events_graph')
        ->thread('collected-events-thread')
        ->input([])
        ->collectEvents()
        ->run();

    expect($run->completed())->toBeTrue()
        ->and($run->events())->toHaveCount(5)
        ->and($run->events()[0])->toBeInstanceOf(RunEvent::class)
        ->and($run->events()[0]->toArray())->toHaveKeys([
            'type',
            'run_id',
            'thread_id',
            'graph_key',
            'node_id',
            'payload',
            'timestamp',
        ])
        ->and($run->events()[4]->type())->toBe('run.completed');
});

it('collects interrupt and failure run events', function () {
    AgentGraph::define(
        StateGraph::make('interrupted_events_graph')
            ->state(['question' => 'string|null'])
            ->node('ask', EventInterruptNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', StateGraph::END)
    );

    AgentGraph::define(
        StateGraph::make('failed_events_graph')
            ->state(['answer' => 'string|null'])
            ->node('fail', EventFailureNode::class)
            ->edge(StateGraph::START, 'fail')
            ->edge('fail', StateGraph::END)
    );

    $interrupted = AgentGraph::graph('interrupted_events_graph')
        ->thread('interrupted-events-thread')
        ->collectEvents()
        ->run();

    $failed = AgentGraph::graph('failed_events_graph')
        ->thread('failed-events-thread')
        ->collectEvents()
        ->run();

    expect($interrupted->interrupted())->toBeTrue()
        ->and(array_map(fn (RunEvent $event): string => $event->type(), $interrupted->events()))->toBe([
            'run.started',
            'node.started',
            'node.completed',
            'checkpoint.created',
            'interrupt.created',
        ])
        ->and($interrupted->events()[4]->payload()['type'])->toBe('input')
        ->and($failed->failed())->toBeTrue()
        ->and(array_map(fn (RunEvent $event): string => $event->type(), $failed->events()))->toBe([
            'run.started',
            'node.started',
            'node.failed',
            'run.failed',
        ])
        ->and($failed->events()[3]->payload()['message'])->toBe('event failure');
});

it('observes resumed interrupted runs through manager resume arguments', function () {
    AgentGraph::define(
        StateGraph::make('resumed_events_graph')
            ->state(['question' => 'string|null', 'answer' => 'string|null'])
            ->node('ask', EventInterruptNode::class)
            ->node('answer', EventResumeAnswerNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', 'answer')
            ->edge('answer', StateGraph::END)
    );

    $interrupted = AgentGraph::graph('resumed_events_graph')
        ->thread('resumed-events-thread')
        ->input([])
        ->run();

    $observed = [];

    $completed = AgentGraph::resume(
        $interrupted->runId(),
        [
            'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
            'question' => 'shipping',
        ],
        function (RunEvent $event) use (&$observed): void {
            $observed[] = $event;
        },
        collectEvents: true,
    );

    expect($completed->completed())->toBeTrue()
        ->and(array_map(fn (RunEvent $event): string => $event->type(), $observed))->toBe([
            'run.resumed',
            'node.started',
            'node.completed',
            'checkpoint.created',
            'node.started',
            'node.completed',
            'checkpoint.created',
            'run.completed',
        ])
        ->and(array_values(array_filter(array_map(fn (RunEvent $event): ?string => $event->nodeId(), $observed))))->toBe([
            'ask',
            'ask',
            'ask',
            'answer',
            'answer',
            'answer',
        ])
        ->and(array_map(fn (RunEvent $event): string => $event->type(), $completed->events()))->toBe([
            'run.resumed',
            'node.started',
            'node.completed',
            'checkpoint.created',
            'node.started',
            'node.completed',
            'checkpoint.created',
            'run.completed',
        ])
        ->and($completed->state('answer'))->toBe('Answered shipping');
});

final class EventAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'ok']);
    }
}

final class EventInterruptNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->state('question') === null) {
            return NodeResult::interrupt('input', ['prompt' => 'What should I answer?']);
        }

        return NodeResult::write([]);
    }
}

final class EventResumeAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'Answered '.$context->state('question')]);
    }
}

final class EventFailureNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::fail('event failure');
    }
}
