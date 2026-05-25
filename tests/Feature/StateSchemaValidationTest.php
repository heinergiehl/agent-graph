<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('rejects invalid run input before creating a run', function () {
    AgentGraph::define(
        StateGraph::make('schema_input_validation')
            ->state(['count' => 'int'])
            ->node('noop', SchemaNoopNode::class)
            ->edge(StateGraph::START, 'noop')
            ->edge('noop', StateGraph::END)
    );

    expect(fn () => AgentGraph::graph('schema_input_validation')->input(['count' => 'not-int'])->run())
        ->toThrow(InvalidArgumentException::class, 'State value [count] must match schema type [int].');

    expect(AgentGraph::runs(['graph_key' => 'schema_input_validation'], limit: 10))->toBeEmpty();
});

it('rejects invalid state edit values before resolving the interrupt', function () {
    AgentGraph::define(
        StateGraph::make('schema_state_edit_validation')
            ->state(['draft' => 'string|null', 'approved' => 'bool|null'])
            ->node('review', SchemaReviewNode::class)
            ->edge(StateGraph::START, 'review')
            ->edge('review', StateGraph::END)
    );

    $run = AgentGraph::graph('schema_state_edit_validation')->run();
    $interrupt = app('agent-graph.interrupts')->pendingForRun($run->runId());

    expect(fn () => AgentGraph::resumeWithStateEdit($run->runId(), $interrupt['interrupt_id'], ['approved' => 'yes']))
        ->toThrow(InvalidArgumentException::class, 'State value [approved] must match schema type [bool|null].');

    expect(app('agent-graph.interrupts')->pendingForRun($run->runId()))->not->toBeNull();
});

it('rejects invalid fork patch values before creating a run', function () {
    AgentGraph::define(SchemaTimeTravelGraph::definition('schema_fork_validation'));

    $run = AgentGraph::graph('schema_fork_validation')->input(['input' => 'hello'])->run();
    $checkpoint = app('agent-graph.checkpoints')->listForRun($run->runId())[0];
    $before = AgentGraph::runs(['graph_key' => 'schema_fork_validation'], limit: 10);

    expect(fn () => AgentGraph::fork($checkpoint['checkpoint_id'], ['input' => 123]))
        ->toThrow(InvalidArgumentException::class, 'State value [input] must match schema type [string|null].');

    expect(AgentGraph::runs(['graph_key' => 'schema_fork_validation'], limit: 10))->toHaveCount(count($before));
});

it('fails a run when a node writes an invalid state value', function () {
    AgentGraph::define(
        StateGraph::make('schema_write_validation')
            ->state(['count' => 'int'])
            ->node('bad', SchemaBadWriteNode::class)
            ->edge(StateGraph::START, 'bad')
            ->edge('bad', StateGraph::END)
    );

    $run = AgentGraph::graph('schema_write_validation')->input(['count' => 1])->run();

    expect($run->failed())->toBeTrue()
        ->and($run->error()['message'])->toContain('State value [count] must match schema type [int].')
        ->and(app('agent-graph.runs')->find($run->runId())['status'])->toBe('failed');
});

it('keeps normal resume compatible with unknown fields but validates known keys', function () {
    AgentGraph::define(
        StateGraph::make('schema_resume_validation')
            ->state(['order_id' => 'string|null', 'answer' => 'string|null'])
            ->node('collect', SchemaCollectNode::class)
            ->node('answer', SchemaAnswerNode::class)
            ->edge(StateGraph::START, 'collect')
            ->edge('collect', 'answer')
            ->edge('answer', StateGraph::END)
    );

    $run = AgentGraph::graph('schema_resume_validation')->run();
    $interrupt = app('agent-graph.interrupts')->pendingForRun($run->runId());

    expect(fn () => AgentGraph::resume($run->runId(), [
        'interrupt_id' => $interrupt['interrupt_id'],
        'order_id' => 123,
        'extra' => true,
    ]))->toThrow(InvalidArgumentException::class, 'State value [order_id] must match schema type [string|null].');

    $completed = AgentGraph::resume($run->runId(), [
        'interrupt_id' => $interrupt['interrupt_id'],
        'order_id' => 'ORD-1',
        'extra' => true,
    ]);

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('answer'))->toBe('Tracking ORD-1');
});

final class SchemaNoopNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([]);
    }
}

final class SchemaReviewNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->state('draft') === null) {
            return NodeResult::interrupt('state_edit', ['title' => 'Edit state']);
        }

        return NodeResult::write(['approved' => true]);
    }
}

final class SchemaBadWriteNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['count' => 'bad']);
    }
}

final class SchemaCollectNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->state('order_id') === null) {
            return NodeResult::interrupt('input', ['title' => 'Order ID']);
        }

        return NodeResult::write([]);
    }
}

final class SchemaAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'Tracking '.$context->state('order_id')]);
    }
}

final class SchemaTimeTravelGraph
{
    public static function definition(string $key): StateGraph
    {
        return StateGraph::make($key)
            ->state(['input' => 'string|null', 'answer' => 'string|null'])
            ->node('answer', SchemaAnswerInputNode::class)
            ->edge(StateGraph::START, 'answer')
            ->edge('answer', StateGraph::END);
    }
}

final class SchemaAnswerInputNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'Answer '.$context->state('input')]);
    }
}
