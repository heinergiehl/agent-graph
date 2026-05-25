<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('lists replay and fork children for a source checkpoint newest first', function () {
    AgentGraph::define(LineageGraph::definition('lineage_children'));

    $original = AgentGraph::graph('lineage_children')->input(['input' => 'source'])->run();
    $sourceCheckpoint = app('agent-graph.checkpoints')->listForRun($original->runId())[0];

    $replayed = AgentGraph::replay($sourceCheckpoint['checkpoint_id']);
    $forked = AgentGraph::fork($sourceCheckpoint['checkpoint_id'], ['input' => 'forked']);

    $children = AgentGraph::timeTravelChildren($sourceCheckpoint['checkpoint_id']);

    expect($children)->toHaveCount(2)
        ->and(array_column($children, 'public_id'))->toBe([$forked->runId(), $replayed->runId()])
        ->and($children[0]['meta']['time_travel']['mode'])->toBe('fork')
        ->and($children[1]['meta']['time_travel']['mode'])->toBe('replay');
});

it('filters by time travel source checkpoint rather than parent checkpoint alone', function () {
    AgentGraph::define(LineageGraph::definition('lineage_source_filter'));

    $original = AgentGraph::graph('lineage_source_filter')->input(['input' => 'source'])->run();
    $sourceCheckpoint = app('agent-graph.checkpoints')->listForRun($original->runId())[0];
    $latestCheckpoint = app('agent-graph.checkpoints')->latestForRun($original->runId());

    AgentGraph::fork($sourceCheckpoint['checkpoint_id'], ['input' => 'forked']);

    expect(AgentGraph::timeTravelChildren($sourceCheckpoint['checkpoint_id']))->toHaveCount(1)
        ->and(AgentGraph::timeTravelChildren($latestCheckpoint['checkpoint_id']))->toBeEmpty();
});

it('limits time travel children and returns an empty list for unknown checkpoints', function () {
    AgentGraph::define(LineageGraph::definition('lineage_limit'));

    $original = AgentGraph::graph('lineage_limit')->input(['input' => 'source'])->run();
    $sourceCheckpoint = app('agent-graph.checkpoints')->listForRun($original->runId())[0];

    AgentGraph::replay($sourceCheckpoint['checkpoint_id']);
    AgentGraph::fork($sourceCheckpoint['checkpoint_id'], ['input' => 'forked']);

    expect(AgentGraph::timeTravelChildren($sourceCheckpoint['checkpoint_id'], limit: 1))->toHaveCount(1)
        ->and(AgentGraph::timeTravelChildren('chk_missing'))->toBeEmpty();
});

final class LineageGraph
{
    public static function definition(string $key): StateGraph
    {
        return StateGraph::make($key)
            ->state(['input' => 'string|null', 'answer' => 'string|null'])
            ->node('prepare', LineagePrepareNode::class)
            ->node('answer', LineageAnswerNode::class)
            ->edge(StateGraph::START, 'prepare')
            ->edge('prepare', 'answer')
            ->edge('answer', StateGraph::END);
    }
}

final class LineagePrepareNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([]);
    }
}

final class LineageAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'Answer '.$context->state('input')]);
    }
}
