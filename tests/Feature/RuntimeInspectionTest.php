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

it('builds a redacted run timeline with diffs and optional full state', function () {
    config([
        'agent-graph.tracing.max_string_length' => 8,
    ]);

    AgentGraph::define(
        StateGraph::make('inspection_timeline')
            ->state([
                'input' => 'string|null',
                'answer' => 'string|null',
                'api_key' => 'string|null',
                'long_text' => 'string|null',
            ])
            ->node('first', TimelineFirstNode::class)
            ->node('second', TimelineSecondNode::class)
            ->edge(StateGraph::START, 'first')
            ->edge('first', 'second')
            ->edge('second', StateGraph::END)
    );

    $run = AgentGraph::graph('inspection_timeline')
        ->thread('timeline-thread-1')
        ->input(['input' => 'hello'])
        ->run();

    $timeline = AgentGraph::timeline($run->runId());
    $steps = $timeline->steps();

    expect($timeline->runId())->toBe($run->runId())
        ->and($timeline->status())->toBe('completed')
        ->and($steps)->toHaveCount(2)
        ->and($steps[0]->step())->toBe(1)
        ->and($steps[0]->nodeId())->toBe('first')
        ->and($steps[0]->status())->toBe('completed')
        ->and($steps[0]->writes())->toHaveCount(3)
        ->and($steps[0]->stateDiff()->toArray()['added']['api_key'])->toBe('[redacted]')
        ->and($steps[0]->stateDiff()->toArray()['added']['long_text'])->toBe('abcdefgh')
        ->and($steps[1]->stateDiff()->toArray()['changed']['answer'])->toBe([
            'before' => 'draft',
            'after' => 'final',
        ])
        ->and($timeline->toArray()['steps'][0])->not->toHaveKey('state_before')
        ->and($timeline->toArray()['steps'][0])->not->toHaveKey('state_after');

    $stateful = AgentGraph::timeline($run->runId(), includeState: true);

    expect($stateful->steps()[0]->stateBefore())->toBe(['input' => 'hello'])
        ->and($stateful->steps()[0]->stateAfter()['answer'])->toBe('draft')
        ->and($stateful->steps()[1]->stateBefore()['answer'])->toBe('draft')
        ->and($stateful->steps()[1]->stateAfter()['answer'])->toBe('final')
        ->and($stateful->toArray()['steps'][0])->toHaveKeys(['state_before', 'state_after']);
});

it('adds state before and state after helpers to checkpoint snapshots', function () {
    AgentGraph::define(
        StateGraph::make('inspection_checkpoint_state')
            ->state(['input' => 'string|null', 'answer' => 'string|null'])
            ->node('first', TimelineCheckpointFirstNode::class)
            ->node('second', TimelineCheckpointSecondNode::class)
            ->edge(StateGraph::START, 'first')
            ->edge('first', 'second')
            ->edge('second', StateGraph::END)
    );

    $run = AgentGraph::graph('inspection_checkpoint_state')
        ->thread('timeline-thread-2')
        ->input(['input' => 'hello'])
        ->run();
    $checkpoints = AgentGraph::inspect($run->runId(), withHistory: true)->checkpoints();

    $first = AgentGraph::checkpoint($checkpoints[0]['checkpoint_id']);
    $second = AgentGraph::checkpoint($checkpoints[1]['checkpoint_id']);

    expect($first->stateBefore())->toBeNull()
        ->and($first->stateAfter()['answer'])->toBe('draft')
        ->and($second->stateBefore()['answer'])->toBe('draft')
        ->and($second->stateAfter()['answer'])->toBe('final');
});

it('builds timeline steps for interrupted delayed failed and skipped nodes', function () {
    Queue::fake();

    AgentGraph::define(
        StateGraph::make('inspection_timeline_statuses')
            ->state(['answer' => 'string|null'])
            ->node('ask', TimelineInterruptNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', StateGraph::END)
    );

    AgentGraph::define(
        StateGraph::make('inspection_timeline_delay')
            ->state(['answer' => 'string|null'])
            ->node('wait', TimelineDelayNode::class)
            ->edge(StateGraph::START, 'wait')
            ->edge('wait', StateGraph::END)
    );

    AgentGraph::define(
        StateGraph::make('inspection_timeline_failure')
            ->state(['answer' => 'string|null'])
            ->node('fail', TimelineThrowingNode::class)
            ->edge(StateGraph::START, 'fail')
            ->edge('fail', StateGraph::END)
    );

    AgentGraph::define(
        StateGraph::make('inspection_timeline_skipped')
            ->state(['answer' => 'string|null'])
            ->node('skip', TimelineSkippedNode::class)
            ->edge(StateGraph::START, 'skip')
            ->edge('skip', StateGraph::END)
    );

    $interrupted = AgentGraph::graph('inspection_timeline_statuses')->thread('timeline-thread-3')->run();
    $delayed = AgentGraph::graph('inspection_timeline_delay')->thread('timeline-thread-4')->run();
    $failed = AgentGraph::graph('inspection_timeline_failure')->thread('timeline-thread-5')->run();
    $skipped = AgentGraph::graph('inspection_timeline_skipped')->thread('timeline-thread-6')->run();

    expect(AgentGraph::timeline($interrupted->runId())->steps()[0]->status())->toBe('interrupted')
        ->and(AgentGraph::timeline($interrupted->runId())->steps()[0]->interrupt()['type'])->toBe('input')
        ->and(AgentGraph::timeline($delayed->runId())->steps()[0]->status())->toBe('delayed')
        ->and(AgentGraph::timeline($failed->runId())->steps()[0]->status())->toBe('failed')
        ->and(AgentGraph::timeline($failed->runId())->steps()[0]->error()['message'])->toBe('timeline failed')
        ->and(AgentGraph::timeline($skipped->runId())->steps()[0]->status())->toBe('skipped')
        ->and(AgentGraph::timeline($skipped->runId())->steps()[0]->meta()['node'])->toMatchArray([
            'type' => 'utility',
            'label' => 'Skip Me',
            'status' => 'skipped',
        ]);

    $skippedSnapshot = AgentGraph::inspect($skipped->runId(), withHistory: true);

    expect($skippedSnapshot->checkpoints()[0]['meta']['node'])->toMatchArray([
        'type' => 'utility',
        'label' => 'Skip Me',
        'status' => 'skipped',
    ])
        ->and($skippedSnapshot->writes()[0]['meta']['node'])->toMatchArray([
            'type' => 'utility',
            'label' => 'Skip Me',
            'status' => 'skipped',
        ]);
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

final class TimelineFirstNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([
            'answer' => 'draft',
            'api_key' => 'secret-value',
            'long_text' => 'abcdefghijklmnopqrstuvwxyz',
        ])->withNodeMeta([
            'type' => 'draft',
            'label' => 'Draft answer',
        ]);
    }
}

final class TimelineSecondNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'final']);
    }
}

final class TimelineCheckpointFirstNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'draft']);
    }
}

final class TimelineCheckpointSecondNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'final']);
    }
}

final class TimelineInterruptNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::interrupt('input', ['prompt' => 'Need input']);
    }
}

final class TimelineDelayNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::interrupt('delay', ['resume_at' => now()->addMinute()->toISOString()]);
    }
}

final class TimelineThrowingNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        throw new RuntimeException('timeline failed');
    }
}

final class TimelineSkippedNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'unchanged'])
            ->withNodeMeta([
                'type' => 'utility',
                'label' => 'Skip Me',
            ])
            ->skipped();
    }
}
