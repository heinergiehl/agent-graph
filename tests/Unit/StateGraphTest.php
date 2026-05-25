<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\State\Reducer;
use Heiner\AgentGraph\State\StateReducer;

it('compiles a fluent graph definition', function () {
    $graph = StateGraph::make('support_triage')
        ->state([
            'input' => 'string',
            'messages' => 'messages',
            'category' => 'string|null',
            'answer' => 'string|null',
        ])
        ->node('classify', NoopNode::class)
        ->node('answer', NoopNode::class)
        ->edge(StateGraph::START, 'classify')
        ->conditional('classify', fn (array $state) => $state['category'] ?? 'default', [
            'default' => 'answer',
        ])
        ->edge('answer', StateGraph::END)
        ->compile();

    expect($graph->key())->toBe('support_triage')
        ->and($graph->schema())->toHaveKey('messages')
        ->and($graph->node('classify'))->toBe(NoopNode::class)
        ->and($graph->entryNode())->toBe('classify')
        ->and($graph->resolveNext('answer', ['answer' => 'ok']))->toBe([StateGraph::END])
        ->and($graph->resolveNext('classify', ['category' => 'default']))->toBe(['answer']);
});

it('rejects duplicate nodes and unknown edges', function () {
    StateGraph::make('invalid')
        ->node('same', NoopNode::class)
        ->node('same', NoopNode::class);
})->throws(InvalidArgumentException::class, 'already exists');

it('rejects edges to unknown nodes when compiling', function () {
    StateGraph::make('invalid')
        ->node('known', NoopNode::class)
        ->edge(StateGraph::START, 'known')
        ->edge('known', 'missing')
        ->compile();
})->throws(InvalidArgumentException::class, 'Unknown edge target');

it('applies built in reducers deterministically', function () {
    $reducer = new StateReducer([
        'answer' => Reducer::lastWriteWins(),
        'sources' => Reducer::append(),
        'facts' => Reducer::merge(),
        'messages' => Reducer::addMessages(),
        'confidence' => Reducer::maxConfidence(),
    ]);

    $state = $reducer->applyMany([
        'answer' => 'old',
        'sources' => ['a'],
        'facts' => ['plan' => 'free'],
        'messages' => [['role' => 'user', 'content' => 'Hi']],
        'confidence' => 0.4,
    ], [
        ['answer' => 'new', 'sources' => ['b'], 'facts' => ['tier' => 'pro'], 'messages' => [['role' => 'assistant', 'content' => 'Hello']], 'confidence' => 0.9],
        ['sources' => ['c'], 'facts' => ['plan' => 'pro'], 'confidence' => 0.7],
    ]);

    expect($state)->toMatchArray([
        'answer' => 'new',
        'sources' => ['a', 'b', 'c'],
        'facts' => ['plan' => 'pro', 'tier' => 'pro'],
        'messages' => [
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'assistant', 'content' => 'Hello'],
        ],
        'confidence' => 0.9,
    ]);
});

final class NoopNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([]);
    }
}
