<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('rejects replay when checkpoint graph version differs from the registered graph', function () {
    AgentGraph::define(VersionedGraph::definition('versioned_replay', '1'));

    $run = AgentGraph::graph('versioned_replay')->input(['input' => 'first'])->run();
    $checkpoint = app('agent-graph.checkpoints')->listForRun($run->runId())[0];

    AgentGraph::define(VersionedGraph::definition('versioned_replay', '2'));

    expect(fn () => AgentGraph::replay($checkpoint['checkpoint_id']))
        ->toThrow(RuntimeException::class, 'Checkpoint graph version [1] does not match registered graph version [2].');
});

it('rejects fork before creating a run when checkpoint graph version differs', function () {
    AgentGraph::define(VersionedGraph::definition('versioned_fork', '1'));

    $run = AgentGraph::graph('versioned_fork')->input(['input' => 'first'])->run();
    $checkpoint = app('agent-graph.checkpoints')->listForRun($run->runId())[0];
    $before = AgentGraph::runs(['graph_key' => 'versioned_fork'], limit: 10);

    AgentGraph::define(VersionedGraph::definition('versioned_fork', '2'));

    expect(fn () => AgentGraph::fork($checkpoint['checkpoint_id'], ['input' => 'forked']))
        ->toThrow(RuntimeException::class, 'Checkpoint graph version [1] does not match registered graph version [2].');

    expect(AgentGraph::runs(['graph_key' => 'versioned_fork'], limit: 10))->toHaveCount(count($before));
});

it('rejects resume when run graph version differs before resolving the interrupt', function () {
    AgentGraph::define(VersionedGraph::interrupting('versioned_resume', '1'));

    $run = AgentGraph::graph('versioned_resume')->run();
    $interrupt = app('agent-graph.interrupts')->pendingForRun($run->runId());

    AgentGraph::define(VersionedGraph::interrupting('versioned_resume', '2'));

    expect(fn () => AgentGraph::resume($run->runId(), [
        'interrupt_id' => $interrupt['interrupt_id'],
        'input' => 'ok',
    ]))->toThrow(RuntimeException::class, 'Run graph version [1] does not match registered graph version [2].');

    expect(app('agent-graph.interrupts')->pendingForRun($run->runId()))->not->toBeNull();
});

it('allows time travel when graph versions match', function () {
    AgentGraph::define(VersionedGraph::definition('versioned_match', '1'));

    $run = AgentGraph::graph('versioned_match')->input(['input' => 'first'])->run();
    $checkpoint = app('agent-graph.checkpoints')->listForRun($run->runId())[0];

    $replayed = AgentGraph::replay($checkpoint['checkpoint_id']);
    $forked = AgentGraph::fork($checkpoint['checkpoint_id'], ['input' => 'forked']);

    expect($replayed->completed())->toBeTrue()
        ->and($forked->completed())->toBeTrue()
        ->and($forked->state('answer'))->toBe('Answer forked');
});

final class VersionedGraph
{
    public static function definition(string $key, string $version): StateGraph
    {
        return StateGraph::make($key, $version)
            ->state(['input' => 'string|null', 'answer' => 'string|null'])
            ->node('prepare', VersionedPrepareNode::class)
            ->node('answer', VersionedAnswerNode::class)
            ->edge(StateGraph::START, 'prepare')
            ->edge('prepare', 'answer')
            ->edge('answer', StateGraph::END);
    }

    public static function interrupting(string $key, string $version): StateGraph
    {
        return StateGraph::make($key, $version)
            ->state(['input' => 'string|null', 'answer' => 'string|null'])
            ->node('collect', VersionedCollectNode::class)
            ->node('answer', VersionedAnswerNode::class)
            ->edge(StateGraph::START, 'collect')
            ->edge('collect', 'answer')
            ->edge('answer', StateGraph::END);
    }
}

final class VersionedPrepareNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([]);
    }
}

final class VersionedCollectNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->state('input') === null) {
            return NodeResult::interrupt('input', ['title' => 'Input']);
        }

        return NodeResult::write([]);
    }
}

final class VersionedAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'Answer '.$context->state('input')]);
    }
}
