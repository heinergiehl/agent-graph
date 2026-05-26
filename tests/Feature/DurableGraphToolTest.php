<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Laravel\Ai\Tools\Request;

it('starts resumes inspects and cancels active thread workflow sessions', function () {
    AgentGraph::define(
        StateGraph::make('durable_session_graph')
            ->state(['message' => 'string', 'answer' => 'string'])
            ->node('ask', DurableAskNode::class)
            ->node('answer', DurableAnswerNode::class)
            ->edge('__start__', 'ask')
            ->edge('ask', 'answer')
            ->compile(),
    );

    $started = AgentGraph::session('durable_session_graph', 'session-thread')
        ->start(['message' => 'hello']);

    expect($started->status())->toBe('interrupted');

    $status = AgentGraph::session('durable_session_graph', 'session-thread')->status();

    expect($status['run_id'])->toBe($started->runId())
        ->and($status['interrupt']['type'])->toBe('input');

    $completed = AgentGraph::session('durable_session_graph', 'session-thread')
        ->resume([
            'interrupt_id' => $started->interrupt()['interrupt_id'],
            'answer' => 'handled',
        ]);

    expect($completed->status())->toBe('completed')
        ->and($completed->state('answer'))->toBe('handled');

    $cancelled = AgentGraph::session('durable_session_graph', 'session-thread-cancel')
        ->start(['message' => 'cancel me']);

    $cancelled = AgentGraph::session('durable_session_graph', 'session-thread-cancel')->cancel();

    expect($cancelled->status())->toBe('cancelled');
});

it('exposes durable graph sessions as Laravel AI compatible tools without changing GraphTool', function () {
    AgentGraph::define(
        StateGraph::make('durable_tool_graph')
            ->state(['answer' => 'string'])
            ->node('ask', DurableAskNode::class)
            ->node('answer', DurableAnswerNode::class)
            ->edge('__start__', 'ask')
            ->edge('ask', 'answer')
            ->compile(),
    );

    $tool = AgentGraph::durableTool('durable_tool_graph');
    $started = json_decode($tool->handle(new Request(['thread_id' => 'tool-session-thread'])), true);

    expect($started['status'])->toBe('interrupted')
        ->and($started['interrupt']['type'])->toBe('input');

    $completed = json_decode($tool->handle(new Request([
        'thread_id' => 'tool-session-thread',
        'interrupt_id' => $started['interrupt']['interrupt_id'],
        'input' => ['answer' => 'tool answer'],
    ])), true);

    expect($completed['status'])->toBe('completed')
        ->and($completed['state']['answer'])->toBe('tool answer');
});

final class DurableAskNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->hasResumePayload()) {
            return NodeResult::write([]);
        }

        return NodeResult::interrupt('input', ['prompt' => 'Need answer']);
    }
}

final class DurableAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['answer' => (string) $context->state('answer')]);
    }
}
