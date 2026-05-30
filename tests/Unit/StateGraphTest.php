<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Graph\RetryPolicy;
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
        ->and($graph->entryNodes())->toBe(['classify'])
        ->and($graph->resolveNext('answer', ['answer' => 'ok']))->toBe([StateGraph::END])
        ->and($graph->resolveNext('classify', ['category' => 'default']))->toBe(['answer']);
});

it('exposes multiple entry nodes and start successors in edge order', function () {
    $graph = StateGraph::make('multiple_entries')
        ->node('left', NoopNode::class)
        ->node('right', NoopNode::class)
        ->edge(StateGraph::START, 'left')
        ->edge(StateGraph::START, 'right')
        ->compile();

    expect($graph->entryNode())->toBe('left')
        ->and($graph->entryNodes())->toBe(['left', 'right'])
        ->and($graph->successorsOf(StateGraph::START, []))->toBe(['left', 'right']);
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

it('rejects unknown reducer strings', function () {
    expect(fn () => new StateReducer(['items' => 'apend']))
        ->toThrow(InvalidArgumentException::class, 'Unknown reducer [apend]');
});

it('validates retry policies and calculates backoff delays', function () {
    $policy = new RetryPolicy(maxAttempts: 4, delayMs: 100, backoff: 2.0, maxDelayMs: 250);

    expect($policy->maxAttempts())->toBe(4)
        ->and($policy->delayMs())->toBe(100)
        ->and($policy->backoff())->toBe(2.0)
        ->and($policy->maxDelayMs())->toBe(250)
        ->and($policy->delayForAttempt(1))->toBe(100)
        ->and($policy->delayForAttempt(2))->toBe(200)
        ->and($policy->delayForAttempt(3))->toBe(250);
});

it('rejects invalid retry policies', function (array $arguments, string $message) {
    expect(fn () => new RetryPolicy(...$arguments))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'zero attempts' => [['maxAttempts' => 0], 'maxAttempts must be at least 1'],
    'negative delay' => [['delayMs' => -1], 'delayMs must be greater than or equal to 0'],
    'low backoff' => [['backoff' => 0.5], 'backoff must be greater than or equal to 1'],
    'negative max delay' => [['maxDelayMs' => -1], 'maxDelayMs must be greater than or equal to 0'],
]);

it('compiles per node retry policies into graph definitions', function () {
    $graph = StateGraph::make('retry_policy_graph')
        ->node('flaky', NoopNode::class)
        ->edge(StateGraph::START, 'flaky')
        ->edge('flaky', StateGraph::END)
        ->retry('flaky', maxAttempts: 3, delayMs: 10, backoff: 1.5, maxDelayMs: 50)
        ->compile();

    expect($graph->nodePolicy('flaky')->retryPolicy())->not->toBeNull()
        ->and($graph->nodePolicy('flaky')->retryPolicy()->maxAttempts())->toBe(3)
        ->and($graph->nodePolicies())->toHaveKey('flaky')
        ->and($graph->nodePolicy('missing')->retryPolicy())->toBeNull();
});

it('rejects retry policies for unknown nodes when compiling', function () {
    StateGraph::make('invalid_retry_policy_graph')
        ->node('known', NoopNode::class)
        ->edge(StateGraph::START, 'known')
        ->edge('known', StateGraph::END)
        ->retry('missing', maxAttempts: 2)
        ->compile();
})->throws(InvalidArgumentException::class, 'Unknown policy node');

it('rejects semaphore concurrency limits until they are implemented', function () {
    StateGraph::make('invalid_concurrency')
        ->node('call_api', NoopNode::class)
        ->edge(StateGraph::START, 'call_api')
        ->concurrency('call_api', limit: 2)
        ->compile();
})->throws(InvalidArgumentException::class, 'only exclusive node concurrency with limit=1');

final class NoopNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([]);
    }
}
