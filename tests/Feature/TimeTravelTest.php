<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('inspects a specific checkpoint with optional writes', function () {
    AgentGraph::define(TimeTravelGraph::definition('time_travel_checkpoint'));

    $run = AgentGraph::graph('time_travel_checkpoint')
        ->thread('time-travel-thread-1')
        ->input(['input' => 'alpha'])
        ->run();

    $checkpoint = app('agent-graph.checkpoints')->listForRun($run->runId())[0];
    $snapshot = AgentGraph::checkpoint($checkpoint['checkpoint_id'], withWrites: true);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->checkpointId())->toBe($checkpoint['checkpoint_id'])
        ->and($snapshot->runId())->toBe($run->runId())
        ->and($snapshot->threadId())->toBe('time-travel-thread-1')
        ->and($snapshot->graphKey())->toBe('time_travel_checkpoint')
        ->and($snapshot->step())->toBe(1)
        ->and($snapshot->state('stage'))->toBe('classified')
        ->and($snapshot->nextNodes())->toBe(['answer'])
        ->and($snapshot->writes())->toHaveCount(2)
        ->and(AgentGraph::checkpoint('chk_missing'))->toBeNull();
});

it('replays from a middle checkpoint without mutating the original run', function () {
    AgentGraph::define(TimeTravelGraph::definition('time_travel_replay'));

    $original = AgentGraph::graph('time_travel_replay')
        ->thread('time-travel-thread-2')
        ->input(['input' => 'beta'])
        ->run();

    $sourceCheckpoint = app('agent-graph.checkpoints')->listForRun($original->runId())[0];
    $replayed = AgentGraph::replay($sourceCheckpoint['checkpoint_id']);

    expect($replayed->completed())->toBeTrue()
        ->and($replayed->runId())->not->toBe($original->runId())
        ->and($replayed->threadId())->toBe($original->threadId())
        ->and($replayed->state('stage'))->toBe('classified')
        ->and($replayed->state('answer'))->toBe('Answer classified beta')
        ->and(app('agent-graph.checkpoints')->listForRun($original->runId()))->toHaveCount(2)
        ->and(app('agent-graph.checkpoints')->listForRun($replayed->runId()))->toHaveCount(1)
        ->and(app('agent-graph.checkpoints')->listForRun($replayed->runId())[0]['parent_checkpoint_id'])
        ->toBe($sourceCheckpoint['checkpoint_id']);
});

it('forks from a checkpoint with a reducer-aware state patch', function () {
    AgentGraph::define(TimeTravelGraph::definition('time_travel_fork'));

    $original = AgentGraph::graph('time_travel_fork')
        ->thread('time-travel-thread-3')
        ->input(['input' => 'gamma'])
        ->run();

    $sourceCheckpoint = app('agent-graph.checkpoints')->listForRun($original->runId())[0];
    $forked = AgentGraph::fork($sourceCheckpoint['checkpoint_id'], [
        'input' => 'forked',
        'events' => ['patched'],
    ]);

    $forkCheckpoints = app('agent-graph.checkpoints')->listForRun($forked->runId());
    $forkRun = app('agent-graph.runs')->find($forked->runId());

    expect($forked->completed())->toBeTrue()
        ->and($forked->runId())->not->toBe($original->runId())
        ->and($forked->state('input'))->toBe('forked')
        ->and($forked->state('events'))->toBe(['classified', 'patched', 'answered'])
        ->and($forkCheckpoints)->toHaveCount(2)
        ->and($forkCheckpoints[0]['parent_checkpoint_id'])->toBe($sourceCheckpoint['checkpoint_id'])
        ->and($forkCheckpoints[0]['meta']['source'])->toBe('fork')
        ->and($forkRun['meta']['time_travel']['mode'])->toBe('fork')
        ->and(app('agent-graph.checkpoints')->listForRun($original->runId()))->toHaveCount(2);
});

it('rejects invalid fork state patches before creating a new run', function () {
    AgentGraph::define(TimeTravelGraph::definition('time_travel_invalid_fork'));

    $original = AgentGraph::graph('time_travel_invalid_fork')
        ->thread('time-travel-thread-4')
        ->input(['input' => 'delta'])
        ->run();

    $sourceCheckpoint = app('agent-graph.checkpoints')->listForRun($original->runId())[0];
    $beforeRuns = AgentGraph::runs(['graph_key' => 'time_travel_invalid_fork'], limit: 10);

    expect(fn () => AgentGraph::fork($sourceCheckpoint['checkpoint_id'], ['unknown' => true]))
        ->toThrow(InvalidArgumentException::class, 'unknown state key [unknown]');

    expect(AgentGraph::runs(['graph_key' => 'time_travel_invalid_fork'], limit: 10))->toHaveCount(count($beforeRuns));
});

it('forks from a checkpoint using successors of an explicit node', function () {
    AgentGraph::define(TimeTravelGraph::definition('time_travel_as_node'));

    $original = AgentGraph::graph('time_travel_as_node')
        ->thread('time-travel-thread-5')
        ->input(['input' => 'epsilon'])
        ->run();

    $sourceCheckpoint = app('agent-graph.checkpoints')->listForRun($original->runId())[0];
    $forked = AgentGraph::fork($sourceCheckpoint['checkpoint_id'], ['input' => 'as-node'], asNode: 'classify');

    expect($forked->completed())->toBeTrue()
        ->and($forked->state('answer'))->toBe('Answer classified as-node');
});

it('forks from a checkpoint using start and end endpoints', function () {
    AgentGraph::define(TimeTravelGraph::definition('time_travel_endpoint_fork'));

    $original = AgentGraph::graph('time_travel_endpoint_fork')
        ->thread('time-travel-thread-7')
        ->input(['input' => 'eta'])
        ->run();

    $sourceCheckpoint = app('agent-graph.checkpoints')->listForRun($original->runId())[0];
    $fromStart = AgentGraph::fork($sourceCheckpoint['checkpoint_id'], ['input' => 'restart'], asNode: StateGraph::START);
    $fromEnd = AgentGraph::fork($sourceCheckpoint['checkpoint_id'], ['input' => 'terminal'], asNode: StateGraph::END);

    expect($fromStart->completed())->toBeTrue()
        ->and($fromStart->state('events'))->toBe(['classified', 'classified', 'answered'])
        ->and($fromStart->state('answer'))->toBe('Answer classified restart')
        ->and($fromEnd->completed())->toBeTrue()
        ->and($fromEnd->state('answer'))->toBeNull()
        ->and(app('agent-graph.checkpoints')->listForRun($fromEnd->runId()))->toHaveCount(1);
});

it('rejects fork from an unknown endpoint', function () {
    AgentGraph::define(TimeTravelGraph::definition('time_travel_unknown_endpoint'));

    $original = AgentGraph::graph('time_travel_unknown_endpoint')
        ->thread('time-travel-thread-8')
        ->input(['input' => 'theta'])
        ->run();

    $sourceCheckpoint = app('agent-graph.checkpoints')->listForRun($original->runId())[0];

    expect(fn () => AgentGraph::fork($sourceCheckpoint['checkpoint_id'], asNode: 'missing'))
        ->toThrow(InvalidArgumentException::class, 'Unknown endpoint [missing] for fork.');
});

it('replays and forks completed checkpoints without re-executing nodes', function () {
    AgentGraph::define(TimeTravelGraph::definition('time_travel_completed_source'));

    $original = AgentGraph::graph('time_travel_completed_source')
        ->thread('time-travel-thread-6')
        ->input(['input' => 'zeta'])
        ->run();

    $completedCheckpoint = app('agent-graph.checkpoints')->latestForRun($original->runId());
    $replayed = AgentGraph::replay($completedCheckpoint['checkpoint_id']);
    $forked = AgentGraph::fork($completedCheckpoint['checkpoint_id'], ['input' => 'changed']);

    expect($replayed->completed())->toBeTrue()
        ->and($replayed->state('answer'))->toBe('Answer classified zeta')
        ->and(app('agent-graph.checkpoints')->listForRun($replayed->runId()))->toHaveCount(1)
        ->and($forked->completed())->toBeTrue()
        ->and($forked->state('answer'))->toBe('Answer classified zeta')
        ->and(app('agent-graph.checkpoints')->listForRun($forked->runId()))->toHaveCount(1);
});

final class TimeTravelGraph
{
    public static function definition(string $key): StateGraph
    {
        return StateGraph::make($key)
            ->state([
                'input' => 'string|null',
                'stage' => 'string|null',
                'answer' => 'string|null',
                'events' => 'array',
            ])
            ->reducer('events', 'append')
            ->node('classify', TimeTravelClassifyNode::class)
            ->node('answer', TimeTravelAnswerNode::class)
            ->edge(StateGraph::START, 'classify')
            ->edge('classify', 'answer')
            ->edge('answer', StateGraph::END);
    }
}

final class TimeTravelClassifyNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([
            'stage' => 'classified',
            'events' => ['classified'],
        ]);
    }
}

final class TimeTravelAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([
            'answer' => 'Answer '.$context->state('stage').' '.$context->state('input'),
            'events' => ['answered'],
        ]);
    }
}
