<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Events\GraphNodeRetrying;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunEvent;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    RetryFlakyNode::$attempts = 0;
    RetryAlwaysThrowNode::$attempts = 0;
    RetryFailResultNode::$attempts = 0;
    RetryWhenGuardNode::$attempts = 0;
    RetryInvalidWriteNode::$attempts = 0;
    RetrySuperstepFlakyNode::$attempts = 0;
    RetrySuperstepStableNode::$attempts = 0;
});

it('retries thrown node exceptions before completing with one checkpoint', function () {
    Event::fake([GraphNodeRetrying::class]);

    AgentGraph::define(
        StateGraph::make('retry_flaky_success')
            ->state(['answer' => 'string|null'])
            ->node('flaky', RetryFlakyNode::class)
            ->edge(StateGraph::START, 'flaky')
            ->edge('flaky', StateGraph::END)
            ->retry('flaky', maxAttempts: 3, delayMs: 0, backoff: 2.0)
    );

    $run = AgentGraph::graph('retry_flaky_success')
        ->thread('retry-thread-1')
        ->collectEvents()
        ->run();

    $checkpoints = app('agent-graph.checkpoints')->listForRun($run->runId());
    $writes = app('agent-graph.writes')->listForRun($run->runId());
    $retryEvents = collect($run->events())->where(fn (RunEvent $event): bool => $event->type() === 'node.retrying')->values();
    $retryTraces = collect(app('agent-graph.traces')->listForRun($run->runId()))
        ->where('event', 'node.retrying')
        ->values();

    expect($run->completed())->toBeTrue()
        ->and($run->state('answer'))->toBe('recovered')
        ->and(RetryFlakyNode::$attempts)->toBe(3)
        ->and($checkpoints)->toHaveCount(1)
        ->and($writes)->toHaveCount(1)
        ->and($writes[0]['meta']['runtime']['retry'])->toMatchArray([
            'attempts' => 3,
            'max_attempts' => 3,
            'failed_attempts' => 2,
        ])
        ->and($retryEvents)->toHaveCount(2)
        ->and($retryEvents[0]->payload())->toMatchArray([
            'attempt' => 1,
            'next_attempt' => 2,
            'max_attempts' => 3,
            'delay_ms' => 0,
        ])
        ->and($retryEvents[0]->payload()['error']['message'])->toBe('temporary 1')
        ->and($retryEvents[1]->payload())->toMatchArray([
            'attempt' => 2,
            'next_attempt' => 3,
            'max_attempts' => 3,
            'delay_ms' => 0,
        ])
        ->and($retryEvents[1]->payload()['error']['message'])->toBe('temporary 2')
        ->and($retryTraces)->toHaveCount(2);

    Event::assertDispatched(GraphNodeRetrying::class, 2);
});

it('fails after retry exhaustion and preserves failed run events', function () {
    AgentGraph::define(
        StateGraph::make('retry_exhaustion')
            ->state(['answer' => 'string|null'])
            ->node('fail', RetryAlwaysThrowNode::class)
            ->edge(StateGraph::START, 'fail')
            ->edge('fail', StateGraph::END)
            ->retry('fail', maxAttempts: 3, delayMs: 0)
    );

    $run = AgentGraph::graph('retry_exhaustion')
        ->thread('retry-thread-2')
        ->collectEvents()
        ->run();

    expect($run->failed())->toBeTrue()
        ->and($run->error()['message'])->toBe('permanent 3')
        ->and(RetryAlwaysThrowNode::$attempts)->toBe(3)
        ->and(array_map(fn (RunEvent $event): string => $event->type(), $run->events()))->toBe([
            'run.started',
            'node.started',
            'node.retrying',
            'node.retrying',
            'node.failed',
            'run.failed',
        ]);
});

it('does not retry intentional NodeResult failures', function () {
    AgentGraph::define(
        StateGraph::make('retry_result_failure')
            ->state(['answer' => 'string|null'])
            ->node('fail', RetryFailResultNode::class)
            ->edge(StateGraph::START, 'fail')
            ->edge('fail', StateGraph::END)
            ->retry('fail', maxAttempts: 3, delayMs: 0)
    );

    $run = AgentGraph::graph('retry_result_failure')
        ->thread('retry-thread-3')
        ->collectEvents()
        ->run();

    expect($run->failed())->toBeTrue()
        ->and($run->error()['message'])->toBe('intentional failure')
        ->and(RetryFailResultNode::$attempts)->toBe(1)
        ->and(collect($run->events())->where(fn (RunEvent $event): bool => $event->type() === 'node.retrying'))->toHaveCount(0);
});

it('stops retrying when the retry predicate returns false', function () {
    AgentGraph::define(
        StateGraph::make('retry_when_false')
            ->state(['answer' => 'string|null'])
            ->node('guarded', RetryWhenGuardNode::class)
            ->edge(StateGraph::START, 'guarded')
            ->edge('guarded', StateGraph::END)
            ->retry('guarded', maxAttempts: 3, delayMs: 0, when: fn () => false)
    );

    $run = AgentGraph::graph('retry_when_false')
        ->thread('retry-thread-4')
        ->collectEvents()
        ->run();

    expect($run->failed())->toBeTrue()
        ->and($run->error()['message'])->toBe('guarded failure 1')
        ->and(RetryWhenGuardNode::$attempts)->toBe(1)
        ->and(collect($run->events())->where(fn (RunEvent $event): bool => $event->type() === 'node.retrying'))->toHaveCount(0);
});

it('does not retry schema validation failures', function () {
    AgentGraph::define(
        StateGraph::make('retry_invalid_write')
            ->state(['answer' => 'string|null'])
            ->node('invalid', RetryInvalidWriteNode::class)
            ->edge(StateGraph::START, 'invalid')
            ->edge('invalid', StateGraph::END)
            ->retry('invalid', maxAttempts: 3, delayMs: 0)
    );

    $run = AgentGraph::graph('retry_invalid_write')
        ->thread('retry-thread-invalid-write')
        ->collectEvents()
        ->run();

    expect($run->failed())->toBeTrue()
        ->and(RetryInvalidWriteNode::$attempts)->toBe(1)
        ->and(collect($run->events())->where(fn (RunEvent $event): bool => $event->type() === 'node.retrying'))->toHaveCount(0);
});

it('retries inside a superstep and merges successful writes once', function () {
    AgentGraph::define(
        StateGraph::make('retry_superstep')
            ->state(['results' => 'array'])
            ->reducer('results', 'append')
            ->node('split', RetrySuperstepSplitNode::class)
            ->node('flaky', RetrySuperstepFlakyNode::class)
            ->node('stable', RetrySuperstepStableNode::class)
            ->edge(StateGraph::START, 'split')
            ->edge('split', 'flaky')
            ->edge('split', 'stable')
            ->retry('flaky', maxAttempts: 2, delayMs: 0)
    );

    $run = AgentGraph::graph('retry_superstep')
        ->thread('retry-thread-5')
        ->input(['results' => []])
        ->collectEvents()
        ->run();

    $checkpoints = app('agent-graph.checkpoints')->listForRun($run->runId());
    $writes = app('agent-graph.writes')->listForRun($run->runId());

    expect($run->completed())->toBeTrue()
        ->and($run->state('results'))->toBe(['flaky', 'stable'])
        ->and(RetrySuperstepFlakyNode::$attempts)->toBe(2)
        ->and(RetrySuperstepStableNode::$attempts)->toBe(1)
        ->and($checkpoints)->toHaveCount(2)
        ->and($checkpoints[1]['completed_nodes'])->toBe(['flaky', 'stable'])
        ->and($writes)->toHaveCount(2)
        ->and(collect($writes)->where('node_id', 'flaky'))->toHaveCount(1)
        ->and(collect($run->events())->where(fn (RunEvent $event): bool => $event->type() === 'node.retrying'))->toHaveCount(1);
});

final class RetryFlakyNode implements Node
{
    public static int $attempts = 0;

    public function __invoke(NodeContext $context): NodeResult
    {
        self::$attempts++;

        if (self::$attempts < 3) {
            throw new RuntimeException('temporary '.self::$attempts);
        }

        return NodeResult::write(['answer' => 'recovered']);
    }
}

final class RetryAlwaysThrowNode implements Node
{
    public static int $attempts = 0;

    public function __invoke(NodeContext $context): NodeResult
    {
        self::$attempts++;

        throw new RuntimeException('permanent '.self::$attempts);
    }
}

final class RetryFailResultNode implements Node
{
    public static int $attempts = 0;

    public function __invoke(NodeContext $context): NodeResult
    {
        self::$attempts++;

        return NodeResult::fail('intentional failure');
    }
}

final class RetryWhenGuardNode implements Node
{
    public static int $attempts = 0;

    public function __invoke(NodeContext $context): NodeResult
    {
        self::$attempts++;

        throw new RuntimeException('guarded failure '.self::$attempts);
    }
}

final class RetryInvalidWriteNode implements Node
{
    public static int $attempts = 0;

    public function __invoke(NodeContext $context): NodeResult
    {
        self::$attempts++;

        return NodeResult::write(['answer' => ['not' => 'a string']]);
    }
}

final class RetrySuperstepSplitNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([]);
    }
}

final class RetrySuperstepFlakyNode implements Node
{
    public static int $attempts = 0;

    public function __invoke(NodeContext $context): NodeResult
    {
        self::$attempts++;

        if (self::$attempts === 1) {
            throw new RuntimeException('superstep transient');
        }

        return NodeResult::write(['results' => ['flaky']]);
    }
}

final class RetrySuperstepStableNode implements Node
{
    public static int $attempts = 0;

    public function __invoke(NodeContext $context): NodeResult
    {
        self::$attempts++;

        return NodeResult::write(['results' => ['stable']]);
    }
}
