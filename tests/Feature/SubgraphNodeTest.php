<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\SubgraphNode;

it('runs mapped subgraphs as child runs and maps child output into parent state', function () {
    AgentGraph::define(
        StateGraph::make('child_mapped_graph')
            ->state(['child_input' => 'string', 'child_answer' => 'string'])
            ->node('answer', ChildMappedNode::class)
            ->edge('__start__', 'answer')
            ->compile(),
    );

    AgentGraph::define(
        StateGraph::make('parent_mapped_graph')
            ->state(['message' => 'string', 'answer' => 'string'])
            ->node('child', SubgraphNode::make('child', 'child_mapped_graph')
                ->mapped(
                    input: fn (array $state) => ['child_input' => $state['message']],
                    output: fn (array $childState) => ['answer' => $childState['child_answer']],
                ))
            ->edge('__start__', 'child')
            ->compile(),
    );

    $run = AgentGraph::graph('parent_mapped_graph')
        ->thread('parent-thread')
        ->input(['message' => 'hello'])
        ->run();

    expect($run->status())->toBe('completed')
        ->and($run->state('answer'))->toBe('child: hello')
        ->and(AgentGraph::childRuns($run->runId()))->toHaveCount(1);
});

it('bubbles child interrupts and resumes the child before completing the parent node', function () {
    AgentGraph::define(
        StateGraph::make('child_interrupt_graph')
            ->state(['answer' => 'string'])
            ->node('ask', ChildInterruptAskNode::class)
            ->node('done', ChildInterruptDoneNode::class)
            ->edge('__start__', 'ask')
            ->edge('ask', 'done')
            ->compile(),
    );

    AgentGraph::define(
        StateGraph::make('parent_interrupt_graph')
            ->state(['answer' => 'string'])
            ->node('child', SubgraphNode::make('child', 'child_interrupt_graph')
                ->mapped(output: fn (array $childState) => ['answer' => $childState['answer']]))
            ->edge('__start__', 'child')
            ->compile(),
    );

    $run = AgentGraph::graph('parent_interrupt_graph')->thread('parent-interrupt-thread')->run();

    expect($run->status())->toBe('interrupted')
        ->and($run->interrupt()['type'])->toBe('subgraph')
        ->and($run->interrupt()['payload']['child_run_id'])->toStartWith('run_');

    $completed = AgentGraph::resume($run->runId(), [
        'interrupt_id' => $run->interrupt()['interrupt_id'],
        'child_run_id' => $run->interrupt()['payload']['child_run_id'],
        'child_interrupt_id' => $run->interrupt()['payload']['child_interrupt_id'],
        'answer' => 'nested',
    ]);

    expect($completed->status())->toBe('completed')
        ->and($completed->state('answer'))->toBe('nested');
});

final class ChildMappedNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['child_answer' => 'child: '.$context->state('child_input')]);
    }
}

final class ChildInterruptAskNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->hasResumePayload()) {
            return NodeResult::write([]);
        }

        return NodeResult::interrupt('input', ['prompt' => 'Nested answer']);
    }
}

final class ChildInterruptDoneNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['answer' => (string) $context->state('answer')]);
    }
}
