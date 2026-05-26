<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\ConcurrencyPolicy;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Graph\TimeoutPolicy;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('strict resume rejects unknown state keys while normal resume stays permissive', function () {
    AgentGraph::define(
        StateGraph::make('strict_resume_graph')
            ->state(['answer' => 'string'])
            ->node('ask', StrictResumeAskNode::class)
            ->node('answer', StrictResumeAnswerNode::class)
            ->edge('__start__', 'ask')
            ->edge('ask', 'answer')
            ->compile(),
    );

    $run = AgentGraph::graph('strict_resume_graph')->thread('strict-resume-thread')->run();

    expect(fn () => AgentGraph::resumeStrict($run->runId(), [
        'interrupt_id' => $run->interrupt()['interrupt_id'],
        'unexpected' => 'no',
    ]))->toThrow(InvalidArgumentException::class, 'unknown state key [unexpected]');

    $completed = AgentGraph::resume($run->runId(), [
        'interrupt_id' => $run->interrupt()['interrupt_id'],
        'unexpected' => 'allowed',
        'answer' => 'done',
    ]);

    expect($completed->status())->toBe('completed')
        ->and($completed->state('answer'))->toBe('done');
});

it('compiles timeout and concurrency policies and fails nodes that exceed timeout', function () {
    $graph = StateGraph::make('timeout_policy_graph')
        ->state(['done' => 'bool'])
        ->node('slow', SlowNode::class)
        ->timeout('slow', 0.01)
        ->concurrency('slow', limit: 1, key: 'slow-node')
        ->edge('__start__', 'slow')
        ->compile();

    $policy = $graph->nodePolicy('slow');

    expect($policy->timeoutPolicy())->toBeInstanceOf(TimeoutPolicy::class)
        ->and($policy->concurrencyPolicy())->toBeInstanceOf(ConcurrencyPolicy::class);

    AgentGraph::define($graph);

    $run = AgentGraph::graph('timeout_policy_graph')->thread('timeout-thread')->run();

    expect($run->status())->toBe('failed')
        ->and($run->error()['message'])->toContain('timed out');
});

final class StrictResumeAskNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->hasResumePayload()) {
            return NodeResult::write([]);
        }

        return NodeResult::interrupt('input', ['prompt' => 'Answer']);
    }
}

final class StrictResumeAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['answer' => (string) $context->state('answer')]);
    }
}

final class SlowNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        usleep(25_000);

        return NodeResult::end(['done' => true]);
    }
}
