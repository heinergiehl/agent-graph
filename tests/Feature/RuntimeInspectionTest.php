<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Illuminate\Support\Facades\Queue;

it('inspects completed runs with state checkpoint writes and optional traces', function () {
    AgentGraph::define(
        StateGraph::make('inspection_completed')
            ->state(['input' => 'string|null', 'answer' => 'string|null'])
            ->node('answer', InspectionAnswerNode::class)
            ->edge(StateGraph::START, 'answer')
            ->edge('answer', StateGraph::END)
    );

    $run = AgentGraph::graph('inspection_completed')
        ->thread('inspection-thread-1')
        ->input(['input' => 'hello'])
        ->run();

    $snapshot = AgentGraph::inspect($run->runId(), withHistory: true, withTraces: true);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->runId())->toBe($run->runId())
        ->and($snapshot->threadId())->toBe('inspection-thread-1')
        ->and($snapshot->graphKey())->toBe('inspection_completed')
        ->and($snapshot->status())->toBe('completed')
        ->and($snapshot->state('answer'))->toBe('inspected hello')
        ->and($snapshot->checkpoint()['step'])->toBe(1)
        ->and($snapshot->checkpoints())->toHaveCount(1)
        ->and($snapshot->writes())->toHaveCount(1)
        ->and($snapshot->interrupt())->toBeNull()
        ->and($snapshot->traces())->not->toBeEmpty();
});

it('inspects interrupted failed and delayed runs', function () {
    Queue::fake();

    AgentGraph::define(
        StateGraph::make('inspection_interrupted')
            ->state(['answer' => 'string|null'])
            ->node('ask', InspectionInterruptNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', StateGraph::END)
    );

    AgentGraph::define(
        StateGraph::make('inspection_failed')
            ->state(['answer' => 'string|null'])
            ->node('fail', InspectionFailureNode::class)
            ->edge(StateGraph::START, 'fail')
            ->edge('fail', StateGraph::END)
    );

    AgentGraph::define(
        StateGraph::make('inspection_delayed')
            ->state(['ready' => 'bool|null'])
            ->node('wait', InspectionDelayNode::class)
            ->edge(StateGraph::START, 'wait')
            ->edge('wait', StateGraph::END)
    );

    $interrupted = AgentGraph::graph('inspection_interrupted')->thread('inspection-thread-2')->run();
    $failed = AgentGraph::graph('inspection_failed')->thread('inspection-thread-3')->run();
    $delayed = AgentGraph::graph('inspection_delayed')->thread('inspection-thread-4')->run();

    expect(AgentGraph::inspect($interrupted->runId())->interrupt()['type'])->toBe('input')
        ->and(AgentGraph::inspect($failed->runId())->error()['message'])->toBe('inspection failed')
        ->and(AgentGraph::inspect($delayed->runId())->status())->toBe('delayed')
        ->and(AgentGraph::inspect('run_missing'))->toBeNull();
});

it('lists runs with filters in newest first order', function () {
    AgentGraph::define(
        StateGraph::make('inspection_listing')
            ->state(['answer' => 'string|null'])
            ->node('ask', InspectionInterruptNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', StateGraph::END)
    );

    AgentGraph::graph('inspection_listing')->thread('listing-thread-a')->run();
    $second = AgentGraph::graph('inspection_listing')->thread('listing-thread-b')->run();
    $third = AgentGraph::graph('inspection_listing')->thread('listing-thread-b')->run();

    $runs = AgentGraph::runs(['status' => 'interrupted', 'thread_id' => 'listing-thread-b'], limit: 2);

    expect($runs)->toHaveCount(2)
        ->and($runs[0]['public_id'])->toBe($third->runId())
        ->and($runs[1]['public_id'])->toBe($second->runId());
});

final class InspectionAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        $context->traces()->record($context->runId(), 'inspection.custom', ['node' => $context->nodeId()]);

        return NodeResult::write(['answer' => 'inspected '.$context->state('input')]);
    }
}

final class InspectionInterruptNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::interrupt('input', ['prompt' => 'Need input']);
    }
}

final class InspectionFailureNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::fail('inspection failed');
    }
}

final class InspectionDelayNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::interrupt('delay', ['resume_at' => now()->addMinute()->toISOString()]);
    }
}
