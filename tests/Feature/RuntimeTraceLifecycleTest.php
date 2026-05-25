<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('records redacted runtime lifecycle traces with optional state snapshots', function () {
    config()->set('agent-graph.tracing.record_state', true);

    AgentGraph::define(
        StateGraph::make('trace_lifecycle')
            ->state(['input' => 'string|null', 'api_key' => 'string|null', 'answer' => 'string|null'])
            ->node('secret', TraceLifecycleSecretNode::class)
            ->edge(StateGraph::START, 'secret')
            ->edge('secret', StateGraph::END)
    );

    $run = AgentGraph::graph('trace_lifecycle')
        ->thread('thread-trace-lifecycle')
        ->input(['input' => 'Hello'])
        ->run();

    $traces = collect(app('agent-graph.traces')->listForRun($run->runId()));
    $completed = $traces->firstWhere('event', 'node.completed');

    expect($traces->pluck('event')->all())->toContain('node.started', 'node.completed', 'checkpoint.created')
        ->and($completed['payload']['node'])->toBe('secret')
        ->and($completed['payload']['writes']['api_key'])->toBe('[redacted]')
        ->and($completed['payload']['state_after']['api_key'])->toBe('[redacted]');
});

final class TraceLifecycleSecretNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([
            'api_key' => 'secret-value',
            'answer' => 'ok',
        ]);
    }
}
