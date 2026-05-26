<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('lists idempotent tasks for inspector filters', function () {
    AgentGraph::define(
        StateGraph::make('task_inspection')
            ->state([
                'first' => 'bool|null',
                'second' => 'bool|null',
            ])
            ->node('first', FirstInspectableTaskNode::class)
            ->node('second', SecondInspectableTaskNode::class)
            ->edge(StateGraph::START, 'first')
            ->edge('first', 'second')
            ->edge('second', StateGraph::END)
    );

    $run = AgentGraph::graph('task_inspection')
        ->thread('task-inspection-thread')
        ->input([])
        ->run();

    $tasks = AgentGraph::tasks(['run_id' => $run->runId()]);

    expect($run->completed())->toBeTrue()
        ->and($tasks)->toHaveCount(2)
        ->and($tasks[0]['task_key'])->toBe('second-'.$run->runId())
        ->and($tasks[1]['task_key'])->toBe('first-'.$run->runId())
        ->and($tasks[0])->toHaveKeys(['status', 'input_hash', 'input', 'result', 'run_id', 'node_id', 'checkpoint_id'])
        ->and($tasks[0]['status'])->toBe('completed')
        ->and($tasks[0]['result'])->toBe(['side_effect' => 'second']);

    expect(AgentGraph::tasks(['run_id' => $run->runId(), 'node_id' => 'first']))
        ->toHaveCount(1)
        ->and(AgentGraph::tasks(['run_id' => $run->runId(), 'node_id' => 'first'])[0]['task_key'])
        ->toBe('first-'.$run->runId());

    expect(AgentGraph::tasks(['run_id' => $run->runId(), 'status' => 'completed']))
        ->toHaveCount(2);

    $checkpointId = $tasks[0]['checkpoint_id'];

    expect($checkpointId)->not->toBeNull()
        ->and(AgentGraph::tasks(['run_id' => $run->runId(), 'checkpoint_id' => $checkpointId]))
        ->toHaveCount(1)
        ->and(AgentGraph::tasks(['run_id' => $run->runId(), 'checkpoint_id' => $checkpointId])[0]['task_key'])
        ->toBe('second-'.$run->runId());

    expect(AgentGraph::tasks(['run_id' => $run->runId()], limit: 1))
        ->toHaveCount(1)
        ->and(AgentGraph::tasks(['run_id' => $run->runId()], limit: 1)[0]['task_key'])
        ->toBe('second-'.$run->runId());
});

final class FirstInspectableTaskNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        $context->tasks()->once('first-'.$context->runId(), ['step' => 1], fn (): array => [
            'side_effect' => 'first',
        ]);

        return NodeResult::write(['first' => true]);
    }
}

final class SecondInspectableTaskNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        $context->tasks()->once('second-'.$context->runId(), ['step' => 2], fn (): array => [
            'side_effect' => 'second',
        ]);

        return NodeResult::write(['second' => true]);
    }
}
