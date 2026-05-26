<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('runs a graph synchronously and checkpoints every node step', function () {
    AgentGraph::define(
        StateGraph::make('support_triage')
            ->state(['input' => 'string', 'category' => 'string|null', 'answer' => 'string|null'])
            ->node('classify', ClassifyNode::class)
            ->node('answer', AnswerNode::class)
            ->edge(StateGraph::START, 'classify')
            ->edge('classify', 'answer')
            ->edge('answer', StateGraph::END)
    );

    $run = AgentGraph::graph('support_triage')
        ->thread('thread-1')
        ->input(['input' => 'Where is my invoice?'])
        ->run();

    expect($run->completed())->toBeTrue()
        ->and($run->state('category'))->toBe('billing')
        ->and($run->state('answer'))->toBe('Handled billing: Where is my invoice?');

    $checkpoints = app('agent-graph.checkpoints')->listForRun($run->runId());
    $writes = app('agent-graph.writes')->listForRun($run->runId());

    expect($checkpoints)->toHaveCount(2)
        ->and($writes)->toHaveCount(2);
});

it('interrupts and resumes from the latest checkpoint', function () {
    AgentGraph::define(
        StateGraph::make('collect_order')
            ->state(['input' => 'string|null', 'order_id' => 'string|null', 'answer' => 'string|null'])
            ->node('ask', AskForOrderNode::class)
            ->node('answer', OrderAnswerNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', 'answer')
            ->edge('answer', StateGraph::END)
    );

    $interrupted = AgentGraph::graph('collect_order')
        ->thread('thread-2')
        ->input(['input' => 'Track package'])
        ->run();

    expect($interrupted->interrupted())->toBeTrue()
        ->and($interrupted->interrupt()['type'])->toBe('input');

    $completed = AgentGraph::resume($interrupted->runId(), [
        'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
        'order_id' => 'ORD-123',
    ]);

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('order_id'))->toBe('ORD-123')
        ->and($completed->state('answer'))->toBe('Tracking ORD-123');
});

it('exposes resume payload and interrupt id to resumed nodes', function () {
    AgentGraph::define(
        StateGraph::make('resume_context')
            ->state([
                'answer' => 'string|null',
                'resume_seen' => 'bool|null',
                'resume_question' => 'string|null',
                'resume_by_key' => 'array|null',
                'resume_interrupt_id' => 'string|null',
                '__resume' => 'array|null',
            ])
            ->node('ask', ResumeContextNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', StateGraph::END)
    );

    $interrupted = AgentGraph::graph('resume_context')
        ->thread('thread-resume-context')
        ->input([])
        ->run();

    $completed = AgentGraph::resume($interrupted->runId(), [
        'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
        'question' => 'shipping',
        '__resume' => ['source' => 'turn'],
    ]);

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('resume_seen'))->toBeTrue()
        ->and($completed->state('resume_question'))->toBe('shipping')
        ->and($completed->state('resume_by_key'))->toBe(['source' => 'turn'])
        ->and($completed->state('resume_interrupt_id'))->toBe($interrupted->interrupt()['interrupt_id']);
});

it('executes side effect tasks once per idempotency key', function () {
    AgentGraph::define(
        StateGraph::make('tasks')
            ->state(['count' => 'int'])
            ->node('task', TaskNode::class)
            ->edge(StateGraph::START, 'task')
            ->edge('task', StateGraph::END)
    );

    $first = AgentGraph::graph('tasks')->thread('thread-3')->input([])->run();
    $second = AgentGraph::graph('tasks')->thread('thread-3')->input([])->run();

    expect($first->state('count'))->toBe(1)
        ->and($second->state('count'))->toBe(1);
});

it('records structured exception metadata for runtime failures', function () {
    AgentGraph::define(
        StateGraph::make('runtime_exception_metadata')
            ->state(['answer' => 'string|null'])
            ->node('fail', ExceptionMetadataNode::class)
            ->edge(StateGraph::START, 'fail')
            ->edge('fail', StateGraph::END)
    );

    $run = AgentGraph::graph('runtime_exception_metadata')
        ->thread('thread-runtime-exception')
        ->input([])
        ->run();

    $snapshot = AgentGraph::inspect($run->runId(), withTraces: true);
    $failureTrace = collect($snapshot->traces())->firstWhere('event', 'node.failed');

    expect($run->failed())->toBeTrue()
        ->and($run->error())->toMatchArray([
            'message' => 'runtime exploded',
            'exception_class' => DomainException::class,
            'code' => 422,
        ])
        ->and($snapshot->error())->toMatchArray([
            'message' => 'runtime exploded',
            'exception_class' => DomainException::class,
            'code' => 422,
        ])
        ->and($failureTrace['payload'])->toMatchArray([
            'node' => 'fail',
            'message' => 'runtime exploded',
            'exception_class' => DomainException::class,
            'code' => 422,
        ]);
});

final class ClassifyNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['category' => 'billing']);
    }
}

final class AnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([
            'answer' => 'Handled '.$context->state('category').': '.$context->state('input'),
        ]);
    }
}

final class AskForOrderNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->state('order_id') === null) {
            return NodeResult::interrupt('input', ['prompt' => 'What is your order number?']);
        }

        return NodeResult::write([]);
    }
}

final class OrderAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'Tracking '.$context->state('order_id')]);
    }
}

final class TaskNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        $count = $context->tasks()->once(
            key: 'send-once:'.$context->threadId(),
            input: ['thread' => $context->threadId()],
            handler: fn () => 1,
        );

        return NodeResult::write(['count' => $count]);
    }
}

final class ResumeContextNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if (! $context->hasResumePayload()) {
            return NodeResult::interrupt('input', ['prompt' => 'What should I answer?']);
        }

        return NodeResult::write([
            'resume_seen' => true,
            'resume_question' => $context->resumePayload()['question'] ?? null,
            'resume_by_key' => $context->resumePayload('__resume'),
            'resume_interrupt_id' => $context->interruptId(),
        ]);
    }
}

final class ExceptionMetadataNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        throw new DomainException('runtime exploded', 422);
    }
}
