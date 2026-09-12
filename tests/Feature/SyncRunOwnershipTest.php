<?php

use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Contracts\RunStore;
use Heiner\AgentGraph\Events\GraphNodeRetrying;
use Heiner\AgentGraph\Events\GraphNodeStarted;
use Heiner\AgentGraph\Events\GraphResumed;
use Heiner\AgentGraph\Events\GraphRunStarted;
use Heiner\AgentGraph\Exceptions\NodeTimeoutException;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\SubgraphNode;

beforeEach(function () {
    // Models an expired cache lease: another caller can enter while a node runs.
    app()->instance(LockProvider::class, new class implements LockProvider
    {
        public function withLock(string $key, Closure $callback): mixed
        {
            return $callback();
        }
    });
});

it('preserves cancellation against a late synchronous outcome', function (bool $throws) {
    $successors = 0;
    AgentGraph::define(StateGraph::make('sync_cancel')
        ->state(['answer' => 'string'])
        ->node('work', function (NodeContext $context) use ($throws) {
            AgentGraph::cancel($context->runId());
            if ($throws) {
                throw new RuntimeException('Late provider failure');
            }

            return NodeResult::write(['answer' => 'late']);
        })
        ->node('after', function () use (&$successors) {
            $successors++;

            return NodeResult::write([]);
        })
        ->edge(StateGraph::START, 'work')->edge('work', 'after')->edge('after', StateGraph::END));

    $result = AgentGraph::graph('sync_cancel')->thread('sync-cancel')->run();

    expect($result->status())->toBe('cancelled')
        ->and($successors)->toBe(0)
        ->and(app('agent-graph.checkpoints')->latestForRun($result->runId()))->toBeNull();
})->with([false, true]);

it('does not commit results produced under an obsolete run revision', function () {
    AgentGraph::define(StateGraph::make('sync_replaced')
        ->state(['obsolete' => 'bool'])
        ->node('work', function (NodeContext $context) {
            app(RunStore::class)->update($context->runId(), ['meta' => ['replacement' => true]]);

            return NodeResult::write(['obsolete' => true]);
        })->edge(StateGraph::START, 'work')->edge('work', StateGraph::END));

    $result = AgentGraph::graph('sync_replaced')->thread('sync-replaced')->run();

    expect($result->status())->toBe('running')
        ->and(app('agent-graph.checkpoints')->latestForRun($result->runId()))->toBeNull()
        ->and(app(RunStore::class)->find($result->runId())['meta'])->toBe(['replacement' => true]);
});

it('checks cancellation before the first invocation and every retry', function (string $boundary, string $mode) {
    config(['agent-graph.execution.mode' => $mode]);
    $calls = 0;
    $event = $boundary === 'start' ? GraphNodeStarted::class : GraphNodeRetrying::class;
    app('events')->listen($event, fn ($event) => AgentGraph::cancel($event->runId));
    AgentGraph::define(StateGraph::make('guarded_calls')
        ->node('work', function () use (&$calls) {
            $calls++;
            throw new RuntimeException('Retryable failure');
        })->retry('work', 3)->edge(StateGraph::START, 'work'));
    $run = AgentGraph::graph('guarded_calls')->run();
    if ($mode === 'queued_supersteps') {
        AgentGraph::executeQueuedNode(AgentGraph::nodeExecutions($run->runId())[0]['execution_id']);
    }
    expect(AgentGraph::inspect($run->runId())->status())->toBe('cancelled')
        ->and($calls)->toBe($boundary === 'start' ? 0 : 1);
})->with(['start', 'retry'])->with(['sync', 'queued_supersteps']);

it('does not adopt a newer interrupt using an older resume context', function () {
    $calls = 0;
    AgentGraph::define(StateGraph::make('newer_wait')
        ->node('ask', function () use (&$calls) {
            $calls++;

            return NodeResult::interrupt('input');
        })->edge(StateGraph::START, 'ask'));
    $run = AgentGraph::graph('newer_wait')->run();
    app('events')->listen(GraphResumed::class, fn ($event) => AgentGraph::recover($event->runId));
    $result = AgentGraph::resume($run->runId(), ['interrupt_id' => $run->interrupt()['interrupt_id']]);
    expect($calls)->toBe(2)->and($result->status())->toBe('interrupted')
        ->and($result->interrupt()['interrupt_id'])->not->toBe($run->interrupt()['interrupt_id']);
});

it('rolls back an invalid memory wait instead of leaving a running checkpoint', function () {
    AgentGraph::define(StateGraph::make('invalid_delay')
        ->node('wait', fn () => NodeResult::interrupt('delay'))
        ->edge(StateGraph::START, 'wait'));
    $run = AgentGraph::graph('invalid_delay')->run();
    expect($run->status())->toBe('failed')
        ->and($run->error()['message'])->toContain('resume_at')
        ->and(app('agent-graph.checkpoints')->latestForRun($run->runId()))->toBeNull();
});

it('rejects declared parallel waits before any sibling effect', function () {
    $effects = 0;
    AgentGraph::define(StateGraph::make('parallel_wait')
        ->node('effect', function () use (&$effects) {
            $effects++;

            return NodeResult::end();
        })
        ->node('wait', fn () => NodeResult::interrupt('input'))->nodeCanInterrupt('wait')
        ->edge(StateGraph::START, 'effect')->edge(StateGraph::START, 'wait'));
    expect(AgentGraph::graph('parallel_wait')->run()->status())->toBe('failed')->and($effects)->toBe(0);
});

it('stops retries when the shared node deadline expires during backoff', function () {
    $calls = 0;
    AgentGraph::define(StateGraph::make('retry_deadline')
        ->node('work', function () use (&$calls) {
            $calls++;
            throw new RuntimeException('Again');
        })
        ->retry('work', 3, delayMs: 100)->timeout('work', 0.02)->edge(StateGraph::START, 'work'));
    $run = AgentGraph::graph('retry_deadline')->run();
    expect($run->status())->toBe('failed')->and($calls)->toBe(1)
        ->and($run->error()['exception_class'])->toBe(NodeTimeoutException::class);
});

it('does not authorize child work after the parent is cancelled', function () {
    $effects = 0;
    AgentGraph::define(StateGraph::make('guarded_child')
        ->node('effect', function () use (&$effects) {
            $effects++;

            return NodeResult::end();
        })
        ->edge(StateGraph::START, 'effect'));
    AgentGraph::define(StateGraph::make('guarded_parent')->node('child', SubgraphNode::make('child', 'guarded_child'))
        ->edge(StateGraph::START, 'child'));
    app('events')->listen(GraphRunStarted::class, function ($event) {
        if ($event->graphKey === 'guarded_child') {
            AgentGraph::cancel(AgentGraph::inspect($event->runId)->parent()['run_id']);
        }
    });
    $run = AgentGraph::graph('guarded_parent')->run();
    expect($run->status())->toBe('cancelled')->and($effects)->toBe(0);
});

it('does not admit a new task after the node deadline', function () {
    $effects = 0;
    AgentGraph::define(StateGraph::make('task_deadline')
        ->node('work', function (NodeContext $context) use (&$effects) {
            usleep(30000);
            $context->tasks()->once('late-task', [], function () use (&$effects) {
                return ++$effects;
            });

            return NodeResult::end();
        })->timeout('work', 0.01)->edge(StateGraph::START, 'work'));
    $run = AgentGraph::graph('task_deadline')->run();
    expect($run->status())->toBe('failed')->and($effects)->toBe(0);
});
