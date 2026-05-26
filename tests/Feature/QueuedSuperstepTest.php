<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Queue\ContinueSuperstepJob;
use Heiner\AgentGraph\Queue\NodeExecutionJob;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\Send;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('agent-graph.execution.mode', 'queued_supersteps');
    config()->set('agent-graph.execution.queue', 'agent-graph-test');
    Queue::fake();
});

it('dispatches initial node execution jobs and returns a running run in queued mode', function () {
    AgentGraph::define(
        StateGraph::make('queued_dispatch_graph')
            ->state(['items' => 'array'])
            ->node('fanout', QueuedFanoutNode::class)
            ->edge(StateGraph::START, 'fanout')
            ->compile(),
    );

    $run = AgentGraph::graph('queued_dispatch_graph')
        ->thread('queued-dispatch-thread')
        ->input(['items' => []])
        ->run();

    $executions = AgentGraph::nodeExecutions($run->runId());

    expect($run->status())->toBe('running')
        ->and($executions)->toHaveCount(1)
        ->and($executions[0]['status'])->toBe('pending')
        ->and($executions[0]['execution_id'])->toStartWith('nex_')
        ->and($executions[0]['base_state'])->toBe(['items' => []])
        ->and($executions[0]['node_state'])->toBe(['items' => []]);

    Queue::assertPushedOn('agent-graph-test', NodeExecutionJob::class);
});

it('executes queued fanout and fanin through node and continuation jobs', function () {
    AgentGraph::define(
        StateGraph::make('queued_superstep_graph')
            ->state(['items' => 'array'])
            ->reducer('items', fn (array $current, array $next): array => array_merge($current, $next))
            ->node('fanout', QueuedFanoutNode::class)
            ->node('a', QueuedBranchANode::class)
            ->node('b', QueuedBranchBNode::class)
            ->edge(StateGraph::START, 'fanout')
            ->edge('fanout', StateGraph::END)
            ->compile(),
    );

    $run = AgentGraph::graph('queued_superstep_graph')->thread('queued-superstep-thread')->input(['items' => []])->run();

    drainQueuedRun($run->runId());

    $snapshot = AgentGraph::inspect($run->runId(), withHistory: true);

    expect($snapshot->status())->toBe('completed')
        ->and($snapshot->state('items'))->toBe(['a', 'b'])
        ->and($snapshot->checkpoints())->toHaveCount(2)
        ->and(AgentGraph::nodeExecutions($run->runId()))->toHaveCount(3);
});

it('does not duplicate node execution or checkpoints when jobs are retried', function () {
    AgentGraph::define(
        StateGraph::make('queued_retry_idempotent')
            ->state(['items' => 'array'])
            ->reducer('items', fn (array $current, array $next): array => array_merge($current, $next))
            ->node('fanout', QueuedFanoutNode::class)
            ->node('a', QueuedBranchANode::class)
            ->node('b', QueuedBranchBNode::class)
            ->edge(StateGraph::START, 'fanout')
            ->compile(),
    );

    $run = AgentGraph::graph('queued_retry_idempotent')->thread('queued-retry-thread')->input(['items' => []])->run();
    $firstExecution = AgentGraph::nodeExecutions($run->runId())[0];

    (new NodeExecutionJob($firstExecution['execution_id']))->handle(app('agent-graph'));
    (new NodeExecutionJob($firstExecution['execution_id']))->handle(app('agent-graph'));
    (new ContinueSuperstepJob($run->runId(), 1))->handle(app('agent-graph'));
    (new ContinueSuperstepJob($run->runId(), 1))->handle(app('agent-graph'));

    $afterFirstStep = AgentGraph::inspect($run->runId(), withHistory: true);

    expect($afterFirstStep->checkpoints())->toHaveCount(1)
        ->and(AgentGraph::nodeExecutions($run->runId()))->toHaveCount(3);

    drainQueuedRun($run->runId());

    $completed = AgentGraph::inspect($run->runId(), withHistory: true);

    expect($completed->status())->toBe('completed')
        ->and($completed->checkpoints())->toHaveCount(2)
        ->and($completed->state('items'))->toBe(['a', 'b']);
});

it('matches sync reducer conflict failure semantics in queued mode', function () {
    AgentGraph::define(
        StateGraph::make('queued_reducer_conflict')
            ->state(['value' => 'string|null'])
            ->node('fanout', QueuedConflictFanoutNode::class)
            ->node('a', QueuedConflictANode::class)
            ->node('b', QueuedConflictBNode::class)
            ->edge(StateGraph::START, 'fanout')
            ->compile(),
    );

    $run = AgentGraph::graph('queued_reducer_conflict')->thread('queued-conflict-thread')->run();

    drainQueuedRun($run->runId());

    $snapshot = AgentGraph::inspect($run->runId());

    expect($snapshot->status())->toBe('failed')
        ->and($snapshot->error()['message'])->toContain('Concurrent writes to state channel [value] require an explicit reducer.');
});

it('fails parallel interrupts with the existing fan in policy', function () {
    AgentGraph::define(
        StateGraph::make('queued_parallel_interrupts')
            ->state(['review' => 'string|null'])
            ->node('fanout', QueuedInterruptFanoutNode::class)
            ->node('a', QueuedInterruptANode::class)
            ->node('b', QueuedInterruptBNode::class)
            ->edge(StateGraph::START, 'fanout')
            ->compile(),
    );

    $run = AgentGraph::graph('queued_parallel_interrupts')->thread('queued-parallel-interrupt-thread')->run();

    drainQueuedRun($run->runId());

    $snapshot = AgentGraph::inspect($run->runId());

    expect($snapshot->status())->toBe('failed')
        ->and($snapshot->error()['message'])->toContain('Parallel interrupts are not supported in the same superstep.');
});

it('bubbles a single interrupt and resumes through queued workers', function () {
    AgentGraph::define(
        StateGraph::make('queued_single_interrupt')
            ->state(['answer' => 'string|null', 'done' => 'bool|null'])
            ->node('ask', QueuedAskNode::class)
            ->node('done', QueuedDoneNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', 'done')
            ->compile(),
    );

    $run = AgentGraph::graph('queued_single_interrupt')->thread('queued-single-interrupt-thread')->run();

    drainQueuedRun($run->runId());

    $interrupted = AgentGraph::inspect($run->runId());

    expect($interrupted->status())->toBe('interrupted')
        ->and($interrupted->interrupt()['type'])->toBe('input');

    AgentGraph::resume($run->runId(), [
        'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
        'answer' => 'yes',
    ]);

    drainQueuedRun($run->runId());

    $completed = AgentGraph::inspect($run->runId(), withHistory: true);

    expect($completed->status())->toBe('completed')
        ->and($completed->state('answer'))->toBe('yes')
        ->and($completed->state('done'))->toBeTrue();
});

it('no ops late queued jobs for cancelled runs', function () {
    AgentGraph::define(
        StateGraph::make('queued_cancelled_late_job')
            ->state(['items' => 'array'])
            ->node('fanout', QueuedFanoutNode::class)
            ->edge(StateGraph::START, 'fanout')
            ->compile(),
    );

    $run = AgentGraph::graph('queued_cancelled_late_job')->thread('queued-cancelled-thread')->input(['items' => []])->run();
    $execution = AgentGraph::nodeExecutions($run->runId())[0];

    AgentGraph::cancel($run->runId());

    (new NodeExecutionJob($execution['execution_id']))->handle(app('agent-graph'));
    (new ContinueSuperstepJob($run->runId(), 1))->handle(app('agent-graph'));

    $snapshot = AgentGraph::inspect($run->runId(), withHistory: true);

    expect($snapshot->status())->toBe('cancelled')
        ->and($snapshot->checkpoints())->toHaveCount(0)
        ->and(AgentGraph::nodeExecutions($run->runId())[0]['status'])->toBe('pending');
});

function drainQueuedRun(string $runId, int $maxCycles = 10): void
{
    for ($cycle = 0; $cycle < $maxCycles; $cycle++) {
        $executions = AgentGraph::nodeExecutions($runId);

        foreach (array_filter($executions, fn (array $execution): bool => $execution['status'] === 'pending') as $execution) {
            (new NodeExecutionJob($execution['execution_id']))->handle(app('agent-graph'));
        }

        $executions = AgentGraph::nodeExecutions($runId);
        $steps = array_values(array_unique(array_map(fn (array $execution): int => (int) $execution['step'], $executions)));
        sort($steps);

        foreach ($steps as $step) {
            (new ContinueSuperstepJob($runId, $step))->handle(app('agent-graph'));
        }

        $status = AgentGraph::inspect($runId)?->status();

        if (in_array($status, ['completed', 'failed', 'interrupted', 'delayed', 'cancelled'], true)) {
            return;
        }
    }

    throw new RuntimeException("Queued run [{$runId}] did not settle within {$maxCycles} cycles.");
}

final class QueuedFanoutNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::sendMany([
            Send::to('a'),
            Send::to('b'),
        ]);
    }
}

final class QueuedBranchANode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['items' => ['a']]);
    }
}

final class QueuedBranchBNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['items' => ['b']]);
    }
}

final class QueuedConflictFanoutNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::sendMany([Send::to('a'), Send::to('b')]);
    }
}

final class QueuedConflictANode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['value' => 'a']);
    }
}

final class QueuedConflictBNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['value' => 'b']);
    }
}

final class QueuedInterruptFanoutNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::sendMany([Send::to('a'), Send::to('b')]);
    }
}

final class QueuedInterruptANode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::interrupt('input', ['prompt' => 'Review A']);
    }
}

final class QueuedInterruptBNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::interrupt('input', ['prompt' => 'Review B']);
    }
}

final class QueuedAskNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->state('answer') === null) {
            return NodeResult::interrupt('input', ['prompt' => 'Answer?']);
        }

        return NodeResult::write([]);
    }
}

final class QueuedDoneNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['done' => true]);
    }
}
