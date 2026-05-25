<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Queue\ContinueDelayedGraphJob;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Illuminate\Support\Facades\Queue;

it('marks delay interrupts as delayed runs with resume metadata', function () {
    Queue::fake();

    AgentGraph::define(
        StateGraph::make('delay_graph')
            ->state(['ready' => 'bool|null'])
            ->node('wait', DelayNode::class)
            ->edge(StateGraph::START, 'wait')
            ->edge('wait', StateGraph::END)
    );

    $run = AgentGraph::graph('delay_graph')->thread('delay-thread')->input([])->run();

    expect($run->status())->toBe('delayed')
        ->and($run->interrupt()['type'])->toBe('delay');

    Queue::assertPushed(ContinueDelayedGraphJob::class, function (ContinueDelayedGraphJob $job) use ($run): bool {
        return $job->runId === $run->runId()
            && ($job->payload['interrupt_id'] ?? null) === $run->interrupt()['interrupt_id']
            && $job->delay instanceof DateTimeInterface;
    });
});

final class DelayNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::interrupt('delay', ['resume_at' => now()->addMinute()->toISOString()]);
    }
}
