<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\Send;
use Illuminate\Support\Facades\Queue;

it('runs static fan out nodes from the same base state in one superstep', function () {
    AgentGraph::define(
        StateGraph::make('superstep_static_fanout')
            ->state([
                'input' => 'string',
                'left_seen' => 'string|null',
                'right_seen' => 'string|null',
                'left_value' => 'string|null',
                'right_value' => 'string|null',
                'combined' => 'string|null',
            ])
            ->node('split', SuperstepSplitNode::class)
            ->node('left', SuperstepLeftNode::class)
            ->node('right', SuperstepRightNode::class)
            ->node('join', SuperstepJoinNode::class)
            ->edge(StateGraph::START, 'split')
            ->edge('split', 'left')
            ->edge('split', 'right')
            ->edge('left', 'join')
            ->edge('right', 'join')
            ->edge('join', StateGraph::END)
    );

    $run = AgentGraph::graph('superstep_static_fanout')
        ->thread('superstep-thread-1')
        ->input(['input' => 'root'])
        ->run();

    $checkpoints = app('agent-graph.checkpoints')->listForRun($run->runId());
    $writes = app('agent-graph.writes')->listForRun($run->runId());

    expect($run->completed())->toBeTrue()
        ->and($run->state('left_seen'))->toBe('none')
        ->and($run->state('right_seen'))->toBe('none')
        ->and($run->state('combined'))->toBe('left-root+right-root')
        ->and($checkpoints)->toHaveCount(3)
        ->and($checkpoints[1]['completed_nodes'])->toBe(['left', 'right'])
        ->and($checkpoints[1]['step'])->toBe(2)
        ->and($writes)->toHaveCount(5);
});

it('runs conditional fan out nodes in one superstep', function () {
    AgentGraph::define(
        StateGraph::make('superstep_conditional_fanout')
            ->state([
                'input' => 'string',
                'left_seen' => 'string|null',
                'right_seen' => 'string|null',
                'left_value' => 'string|null',
                'right_value' => 'string|null',
                'combined' => 'string|null',
            ])
            ->node('route', SuperstepSplitNode::class)
            ->node('left', SuperstepLeftNode::class)
            ->node('right', SuperstepRightNode::class)
            ->node('join', SuperstepJoinNode::class)
            ->edge(StateGraph::START, 'route')
            ->conditional('route', fn (array $state): array => ['left_path', 'right_path'], [
                'left_path' => 'left',
                'right_path' => 'right',
            ])
            ->edge('left', 'join')
            ->edge('right', 'join')
            ->edge('join', StateGraph::END)
    );

    $run = AgentGraph::graph('superstep_conditional_fanout')
        ->thread('superstep-thread-conditional')
        ->input(['input' => 'conditional'])
        ->run();

    $checkpoints = app('agent-graph.checkpoints')->listForRun($run->runId());

    expect($run->completed())->toBeTrue()
        ->and($run->state('left_seen'))->toBe('none')
        ->and($run->state('right_seen'))->toBe('none')
        ->and($run->state('combined'))->toBe('left-conditional+right-conditional')
        ->and($checkpoints[1]['completed_nodes'])->toBe(['left', 'right']);
});

it('runs dynamic sends with reducer merges', function () {
    AgentGraph::define(
        StateGraph::make('superstep_dynamic_send')
            ->state([
                'items' => 'array',
                'results' => 'array',
                'done' => 'bool|null',
            ])
            ->reducer('results', 'append')
            ->node('dispatch', SuperstepDispatchNode::class)
            ->node('worker', SuperstepWorkerNode::class)
            ->node('done', SuperstepDoneNode::class)
            ->edge(StateGraph::START, 'dispatch')
            ->edge('worker', 'done')
            ->edge('done', StateGraph::END)
    );

    $run = AgentGraph::graph('superstep_dynamic_send')
        ->thread('superstep-thread-2')
        ->input(['items' => ['a', 'b']])
        ->run();

    $checkpoints = app('agent-graph.checkpoints')->listForRun($run->runId());
    $timeline = AgentGraph::timeline($run->runId());

    expect($run->completed())->toBeTrue()
        ->and($run->state('results'))->toBe(['A', 'B'])
        ->and($run->state('done'))->toBeTrue()
        ->and($checkpoints)->toHaveCount(3)
        ->and($checkpoints[0]['meta']['runtime']['schedule']['next'][0]['input'])->toBe(['item' => 'a'])
        ->and($checkpoints[1]['completed_nodes'])->toBe(['worker', 'worker'])
        ->and($checkpoints[1]['state'])->not->toHaveKey('item')
        ->and($timeline->steps()[1]->nodeIds())->toBe(['worker', 'worker'])
        ->and($timeline->steps()[1]->toArray()['completed_nodes'])->toBe(['worker', 'worker']);
});

it('fails concurrent writes to the same channel without an explicit reducer', function () {
    AgentGraph::define(
        StateGraph::make('superstep_conflict')
            ->state(['value' => 'string|null'])
            ->node('split', SuperstepConflictSplitNode::class)
            ->node('one', SuperstepConflictOneNode::class)
            ->node('two', SuperstepConflictTwoNode::class)
            ->edge(StateGraph::START, 'split')
            ->edge('split', 'one')
            ->edge('split', 'two')
    );

    $run = AgentGraph::graph('superstep_conflict')
        ->thread('superstep-thread-3')
        ->run();

    expect($run->status())->toBe('failed')
        ->and($run->error()['message'])->toContain('Concurrent writes to state channel [value]');
});

it('keeps single node interrupts working and rejects parallel interrupts', function () {
    Queue::fake();

    AgentGraph::define(
        StateGraph::make('superstep_single_interrupt')
            ->state(['answer' => 'string|null'])
            ->node('ask', SuperstepAskNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', StateGraph::END)
    );

    AgentGraph::define(
        StateGraph::make('superstep_parallel_interrupt')
            ->state(['answer' => 'string|null'])
            ->node('split', SuperstepConflictSplitNode::class)
            ->node('ask', SuperstepAskNode::class)
            ->node('write', SuperstepWriteAnswerNode::class)
            ->edge(StateGraph::START, 'split')
            ->edge('split', 'ask')
            ->edge('split', 'write')
    );

    $interrupted = AgentGraph::graph('superstep_single_interrupt')->thread('superstep-thread-4')->run();
    $failed = AgentGraph::graph('superstep_parallel_interrupt')->thread('superstep-thread-5')->run();

    expect($interrupted->interrupted())->toBeTrue()
        ->and($interrupted->interrupt()['type'])->toBe('input')
        ->and($failed->status())->toBe('failed')
        ->and($failed->error()['message'])->toContain('Parallel interrupts are not supported');
});

it('preserves scheduled send metadata for replay and fork from parallel checkpoints', function () {
    AgentGraph::define(
        StateGraph::make('superstep_time_travel')
            ->state([
                'items' => 'array',
                'results' => 'array',
                'done' => 'bool|null',
            ])
            ->reducer('results', 'append')
            ->node('dispatch', SuperstepDispatchNode::class)
            ->node('worker', SuperstepWorkerNode::class)
            ->node('done', SuperstepDoneNode::class)
            ->edge(StateGraph::START, 'dispatch')
            ->edge('worker', 'done')
            ->edge('done', StateGraph::END)
    );

    $original = AgentGraph::graph('superstep_time_travel')
        ->thread('superstep-thread-6')
        ->input(['items' => ['x', 'y']])
        ->run();

    $sourceCheckpoint = app('agent-graph.checkpoints')->listForRun($original->runId())[0];
    $replayed = AgentGraph::replay($sourceCheckpoint['checkpoint_id']);
    $forked = AgentGraph::fork($sourceCheckpoint['checkpoint_id'], ['items' => ['m', 'n']]);

    expect($replayed->completed())->toBeTrue()
        ->and($replayed->state('results'))->toBe(['X', 'Y'])
        ->and($forked->completed())->toBeTrue()
        ->and($forked->state('results'))->toBe(['X', 'Y'])
        ->and(app('agent-graph.checkpoints')->listForRun($replayed->runId())[0]['completed_nodes'])->toBe(['worker', 'worker'])
        ->and(app('agent-graph.checkpoints')->listForRun($forked->runId())[1]['completed_nodes'])->toBe(['worker', 'worker']);
});

final class SuperstepSplitNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([]);
    }
}

final class SuperstepLeftNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([
            'left_seen' => $context->state('right_value', 'none'),
            'left_value' => 'left-'.$context->state('input'),
        ]);
    }
}

final class SuperstepRightNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([
            'right_seen' => $context->state('left_value', 'none'),
            'right_value' => 'right-'.$context->state('input'),
        ]);
    }
}

final class SuperstepJoinNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([
            'combined' => $context->state('left_value').'+'.$context->state('right_value'),
        ]);
    }
}

final class SuperstepDispatchNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::sendMany(array_map(
            fn (string $item): Send => Send::to('worker', ['item' => $item]),
            $context->state('items', []),
        ));
    }
}

final class SuperstepWorkerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['results' => [strtoupper($context->state('item'))]]);
    }
}

final class SuperstepDoneNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['done' => true]);
    }
}

final class SuperstepConflictSplitNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([]);
    }
}

final class SuperstepConflictOneNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['value' => 'one']);
    }
}

final class SuperstepConflictTwoNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['value' => 'two']);
    }
}

final class SuperstepAskNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::interrupt('input', ['prompt' => 'Need input']);
    }
}

final class SuperstepWriteAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'ok']);
    }
}
