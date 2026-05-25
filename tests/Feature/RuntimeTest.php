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

it('preserves exception metadata on failed node runs', function () {
    AgentGraph::define(
        StateGraph::make('failing_node')
            ->state([])
            ->node('fail', FailingNode::class)
            ->edge(StateGraph::START, 'fail')
            ->edge('fail', StateGraph::END)
    );

    $run = AgentGraph::graph('failing_node')->thread('thread-4')->input([])->run();

    expect($run->failed())->toBeTrue()
        ->and($run->error()['message'])->toBe('Runtime metadata failure.')
        ->and($run->error()['exception_class'])->toBe(RuntimeMetadataException::class)
        ->and($run->error()['exception_code'])->toBe(42)
        ->and($run->error()['error_code'])->toBe('runtime_metadata_failed')
        ->and($run->error()['http_status'])->toBe(422)
        ->and($run->error()['details'])->toBe(['field' => 'value']);
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

final class FailingNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        throw new RuntimeMetadataException('Runtime metadata failure.', 42);
    }
}

final class RuntimeMetadataException extends RuntimeException
{
    public function errorCode(): string
    {
        return 'runtime_metadata_failed';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    /**
     * @return array<string, string>
     */
    public function details(): array
    {
        return ['field' => 'value'];
    }
}
