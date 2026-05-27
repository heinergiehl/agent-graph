<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('resumes a state edit interrupt after validating the state patch', function () {
    AgentGraph::define(
        StateGraph::make('state_edit_resume')
            ->state(['draft' => 'string|null', 'approved' => 'bool|null'])
            ->node('review', StateEditReviewNode::class)
            ->edge(StateGraph::START, 'review')
            ->edge('review', StateGraph::END)
    );

    $interrupted = AgentGraph::graph('state_edit_resume')
        ->thread('state-edit-thread-1')
        ->input(['draft' => null])
        ->run();

    $completed = AgentGraph::resumeWithStateEdit(
        $interrupted->runId(),
        $interrupted->interrupt()['interrupt_id'],
        ['draft' => 'approved copy'],
        'reviewer-1',
    );

    $interrupt = app('agent-graph.interrupts')->find($interrupted->interrupt()['interrupt_id']);

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('draft'))->toBe('approved copy')
        ->and($completed->state('approved'))->toBeTrue()
        ->and($interrupt['status'])->toBe('resolved')
        ->and($interrupt['resolved_by'])->toBe('reviewer-1');
});

it('rejects unknown state edit keys before resolving the interrupt', function () {
    AgentGraph::define(
        StateGraph::make('state_edit_invalid_keys')
            ->state(['draft' => 'string|null'])
            ->node('review', StateEditReviewNode::class)
            ->edge(StateGraph::START, 'review')
            ->edge('review', StateGraph::END)
    );

    $interrupted = AgentGraph::graph('state_edit_invalid_keys')->thread('state-edit-thread-2')->run();

    expect(fn () => AgentGraph::resumeWithStateEdit(
        $interrupted->runId(),
        $interrupted->interrupt()['interrupt_id'],
        ['unexpected' => 'value'],
    ))->toThrow(InvalidArgumentException::class, 'unknown state key [unexpected]');

    expect(app('agent-graph.interrupts')->find($interrupted->interrupt()['interrupt_id'])['status'])->toBe('pending');
});

it('rejects wrong or stale state edit interrupt ids', function () {
    AgentGraph::define(
        StateGraph::make('state_edit_stale_interrupt')
            ->state(['draft' => 'string|null', 'approved' => 'bool|null'])
            ->node('review', StateEditReviewNode::class)
            ->edge(StateGraph::START, 'review')
            ->edge('review', StateGraph::END)
    );

    $interrupted = AgentGraph::graph('state_edit_stale_interrupt')->thread('state-edit-thread-3')->run();

    expect(fn () => AgentGraph::resumeWithStateEdit(
        $interrupted->runId(),
        'int_missing',
        ['draft' => 'copy'],
    ))->toThrow(InvalidArgumentException::class, 'does not match the pending interrupt');

    AgentGraph::resumeWithStateEdit(
        $interrupted->runId(),
        $interrupted->interrupt()['interrupt_id'],
        ['draft' => 'copy'],
    );

    expect(fn () => AgentGraph::resumeWithStateEdit(
        $interrupted->runId(),
        $interrupted->interrupt()['interrupt_id'],
        ['draft' => 'copy again'],
    ))->toThrow(RuntimeException::class, 'cannot be resumed');
});

it('keeps normal resume compatible for input interrupts', function () {
    AgentGraph::define(
        StateGraph::make('state_edit_normal_resume')
            ->state(['order_id' => 'string|null', 'answer' => 'string|null'])
            ->node('ask', StateEditAskOrderNode::class)
            ->node('answer', StateEditAnswerOrderNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', 'answer')
            ->edge('answer', StateGraph::END)
    );

    $interrupted = AgentGraph::graph('state_edit_normal_resume')->thread('state-edit-thread-4')->run();

    $completed = AgentGraph::resume($interrupted->runId(), [
        'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
        'order_id' => 'ORD-999',
    ]);

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('answer'))->toBe('Tracking ORD-999');
});

final class StateEditReviewNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->state('draft') === null) {
            return NodeResult::interrupt('state_edit', ['title' => 'Edit draft']);
        }

        return NodeResult::write(['approved' => true]);
    }
}

final class StateEditAskOrderNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->state('order_id') === null) {
            return NodeResult::interrupt('input', ['prompt' => 'Order ID?']);
        }

        return NodeResult::write([]);
    }
}

final class StateEditAnswerOrderNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'Tracking '.$context->state('order_id')]);
    }
}
