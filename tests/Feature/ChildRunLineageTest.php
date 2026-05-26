<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('records parent metadata for manually created child runs', function () {
    AgentGraph::define(ChildLineageGraph::definition('child_lineage_manual'));

    $parent = AgentGraph::graph('child_lineage_manual')
        ->thread('child-lineage-parent')
        ->input(['input' => 'parent'])
        ->run();
    $parentCheckpoint = AgentGraph::inspect($parent->runId())->checkpoint();

    $child = AgentGraph::graph('child_lineage_manual')
        ->thread('child-lineage-child')
        ->input(['input' => 'child'])
        ->meta(['tenant' => 'acme'])
        ->parent($parent->runId(), $parentCheckpoint['checkpoint_id'], 'delegate', relationship: 'tool')
        ->run();

    $expectedParent = [
        'run_id' => $parent->runId(),
        'checkpoint_id' => $parentCheckpoint['checkpoint_id'],
        'node_id' => 'delegate',
        'depth' => 1,
        'relationship' => 'tool',
    ];

    expect($child->meta())->toMatchArray([
        'tenant' => 'acme',
        'parent' => $expectedParent,
    ]);

    $snapshot = AgentGraph::inspect($child->runId());
    $children = AgentGraph::childRuns($parent->runId());

    expect($snapshot->parent())->toBe($expectedParent)
        ->and($children)->toHaveCount(1)
        ->and($children[0]['public_id'])->toBe($child->runId())
        ->and($children[0]['meta']['parent'])->toBe($expectedParent);
});

it('lists child runs newest first with a bounded limit', function () {
    AgentGraph::define(ChildLineageGraph::definition('child_lineage_listing'));

    $parent = AgentGraph::graph('child_lineage_listing')->input(['input' => 'parent'])->run();
    $first = AgentGraph::graph('child_lineage_listing')->input(['input' => 'first'])->parent($parent->runId())->run();
    $second = AgentGraph::graph('child_lineage_listing')->input(['input' => 'second'])->parent($parent->runId())->run();

    $children = AgentGraph::childRuns($parent->runId(), limit: 1);

    expect($children)->toHaveCount(1)
        ->and($children[0]['public_id'])->toBe($second->runId())
        ->and(AgentGraph::childRuns('run_missing'))->toBeEmpty()
        ->and($first->meta()['parent']['relationship'])->toBe('child');
});

it('rejects invalid child run parent metadata', function (Closure $callback, string $message) {
    $key = 'child_lineage_invalid_'.str()->ulid();
    AgentGraph::define(ChildLineageGraph::definition($key));

    expect(fn () => $callback(AgentGraph::graph($key)))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty run id' => [
        fn ($pending) => $pending->parent(''),
        'Parent run id must not be empty.',
    ],
    'zero depth' => [
        fn ($pending) => $pending->parent('run_parent', depth: 0),
        'Parent depth must be at least 1.',
    ],
    'empty relationship' => [
        fn ($pending) => $pending->parent('run_parent', relationship: ''),
        'Parent relationship must not be empty.',
    ],
]);

final class ChildLineageGraph
{
    public static function definition(string $key): StateGraph
    {
        return StateGraph::make($key)
            ->state(['input' => 'string|null', 'answer' => 'string|null'])
            ->node('answer', ChildLineageAnswerNode::class)
            ->edge(StateGraph::START, 'answer')
            ->edge('answer', StateGraph::END);
    }
}

final class ChildLineageAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'child lineage '.$context->state('input')]);
    }
}
