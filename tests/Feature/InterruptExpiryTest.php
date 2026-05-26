<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\InterruptPolicy;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('expires pending interrupts through interrupt policies', function () {
    AgentGraph::define(
        StateGraph::make('expiring_interrupt_graph')
            ->node('ask', ExpiringInterruptNode::class)
            ->edge('__start__', 'ask')
            ->compile(),
    );

    $run = AgentGraph::graph('expiring_interrupt_graph')->thread('expiring-thread')->run();

    expect($run->status())->toBe('interrupted')
        ->and($run->interrupt()['expires_at'])->not->toBeNull();

    $expired = AgentGraph::expireInterrupts(now()->addMinute());

    expect($expired)->toBe(1)
        ->and(AgentGraph::inspect($run->runId())->interrupt())->toBeNull();
});

final class ExpiringInterruptNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::interrupt('approval', ['prompt' => 'Approve?'])
            ->withInterruptPolicy(InterruptPolicy::expiresAfter(1));
    }
}
