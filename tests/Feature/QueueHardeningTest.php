<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Queue\ContinueDelayedGraphJob;
use Heiner\AgentGraph\Queue\ContinueSuperstepJob;
use Heiner\AgentGraph\Queue\NodeExecutionJob;
use Heiner\AgentGraph\Queue\ResumeGraphJob;
use Heiner\AgentGraph\Queue\RunGraphJob;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\Send;
use Illuminate\Support\Facades\Queue;

it('applies configured queue job defaults and tags', function () {
    config()->set('agent-graph.execution.job_tries', 5);
    config()->set('agent-graph.execution.job_timeout', 120);
    config()->set('agent-graph.execution.job_backoff', [1, 5, 10]);

    $job = new NodeExecutionJob('nex_test');

    expect($job->tries)->toBe(5)
        ->and($job->timeout)->toBe(120)
        ->and($job->backoff())->toBe([1, 5, 10])
        ->and($job->tags())->toContain('agent-graph', 'agent-graph:node-execution', 'agent-graph:execution:nex_test');
});

it('tags queue jobs by operation and runtime identifiers', function () {
    expect((new RunGraphJob('support_graph', 'thread_1'))->tags())
        ->toContain('agent-graph', 'agent-graph:run', 'agent-graph:graph:support_graph', 'agent-graph:thread:thread_1')
        ->and((new ResumeGraphJob('run_1'))->tags())
        ->toContain('agent-graph', 'agent-graph:resume', 'agent-graph:run:run_1')
        ->and((new ContinueDelayedGraphJob('run_2', ['interrupt_id' => 'int_1']))->tags())
        ->toContain('agent-graph', 'agent-graph:delayed-resume', 'agent-graph:run:run_2')
        ->and((new ContinueSuperstepJob('run_3', 4))->tags())
        ->toContain('agent-graph', 'agent-graph:continue-superstep', 'agent-graph:run:run_3', 'agent-graph:step:4');
});

it('no-ops delayed continuation jobs for final run statuses', function (string $status) {
    Queue::fake();

    AgentGraph::define(
        StateGraph::make('queue_final_'.$status)
            ->state(['ready' => 'bool|null'])
            ->node('wait', QueueHardeningDelayNode::class)
            ->edge(StateGraph::START, 'wait')
            ->edge('wait', StateGraph::END)
    );

    $run = AgentGraph::graph('queue_final_'.$status)->thread('queue-final-'.$status)->run();
    app('agent-graph.runs')->update($run->runId(), ['status' => $status]);

    $before = app('agent-graph.checkpoints')->listForRun($run->runId());
    $result = (new ContinueDelayedGraphJob($run->runId(), [
        'interrupt_id' => $run->interrupt()['interrupt_id'],
    ]))->handle(app('agent-graph'));
    $after = app('agent-graph.checkpoints')->listForRun($run->runId());

    expect($result->status())->toBe($status)
        ->and($after)->toHaveCount(count($before));
})->with(['completed', 'cancelled', 'failed']);

it('does not duplicate checkpoints or writes when delayed jobs are retried', function () {
    Queue::fake();

    AgentGraph::define(
        StateGraph::make('queue_duplicate_delay')
            ->state(['ready' => 'bool|null', 'done' => 'bool|null'])
            ->node('wait', QueueHardeningDelayNode::class)
            ->edge(StateGraph::START, 'wait')
            ->edge('wait', StateGraph::END)
    );

    $run = AgentGraph::graph('queue_duplicate_delay')->thread('queue-duplicate-delay')->run();
    $payload = ['interrupt_id' => $run->interrupt()['interrupt_id']];

    $first = (new ContinueDelayedGraphJob($run->runId(), $payload))->handle(app('agent-graph'));
    $checkpointCount = count(app('agent-graph.checkpoints')->listForRun($run->runId()));
    $writeCount = count(app('agent-graph.writes')->listForRun($run->runId()));

    $second = (new ContinueDelayedGraphJob($run->runId(), $payload))->handle(app('agent-graph'));

    expect($first->completed())->toBeTrue()
        ->and($second->completed())->toBeTrue()
        ->and(app('agent-graph.checkpoints')->listForRun($run->runId()))->toHaveCount($checkpointCount)
        ->and(app('agent-graph.writes')->listForRun($run->runId()))->toHaveCount($writeCount);
});

it('resumes from the latest checkpoint after an interrupted run', function () {
    AgentGraph::define(
        StateGraph::make('queue_latest_checkpoint')
            ->state(['step' => 'string|null', 'answer' => 'string|null'])
            ->node('first', QueueHardeningFirstNode::class)
            ->node('second', QueueHardeningSecondNode::class)
            ->edge(StateGraph::START, 'first')
            ->edge('first', 'second')
            ->edge('second', StateGraph::END)
    );

    $run = AgentGraph::graph('queue_latest_checkpoint')->thread('queue-latest-checkpoint')->run();

    $completed = AgentGraph::resume($run->runId(), [
        'interrupt_id' => $run->interrupt()['interrupt_id'],
        'answer' => 'after interrupt',
    ]);

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('step'))->toBe('first')
        ->and($completed->state('answer'))->toBe('after interrupt')
        ->and(app('agent-graph.checkpoints')->listForRun($run->runId()))->toHaveCount(3);
});

it('does not duplicate queued node execution or continuation job results when retried', function () {
    config()->set('agent-graph.execution.mode', 'queued_supersteps');
    Queue::fake();

    AgentGraph::define(
        StateGraph::make('queue_retry_idempotency')
            ->state(['items' => 'array'])
            ->reducer('items', fn (array $current, array $next): array => array_merge($current, $next))
            ->node('fanout', QueueHardeningFanoutNode::class)
            ->node('a', QueueHardeningBranchANode::class)
            ->node('b', QueueHardeningBranchBNode::class)
            ->edge(StateGraph::START, 'fanout')
            ->compile(),
    );

    $run = AgentGraph::graph('queue_retry_idempotency')
        ->thread('queue-retry-idempotency')
        ->input(['items' => []])
        ->run();
    $execution = AgentGraph::nodeExecutions($run->runId())[0];

    (new NodeExecutionJob($execution['execution_id']))->handle(app('agent-graph'));
    (new NodeExecutionJob($execution['execution_id']))->handle(app('agent-graph'));
    (new ContinueSuperstepJob($run->runId(), 1))->handle(app('agent-graph'));
    (new ContinueSuperstepJob($run->runId(), 1))->handle(app('agent-graph'));

    $snapshot = AgentGraph::inspect($run->runId(), withHistory: true);

    expect($snapshot->status())->toBe('running')
        ->and($snapshot->checkpoints())->toHaveCount(1)
        ->and(AgentGraph::nodeExecutions($run->runId()))->toHaveCount(3)
        ->and(AgentGraph::nodeExecutions($run->runId())[0]['status'])->toBe('completed');
});

final class QueueHardeningDelayNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->state('ready') !== true) {
            return NodeResult::interrupt(
                'delay',
                ['resume_at' => now()->addMinute()->toISOString()],
                ['ready' => true],
            );
        }

        return NodeResult::write(['done' => true]);
    }
}

final class QueueHardeningFirstNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['step' => 'first']);
    }
}

final class QueueHardeningSecondNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->state('answer') === null) {
            return NodeResult::interrupt('input', ['prompt' => 'Answer?']);
        }

        return NodeResult::write([]);
    }
}

final class QueueHardeningFanoutNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::sendMany([
            Send::to('a'),
            Send::to('b'),
        ]);
    }
}

final class QueueHardeningBranchANode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['items' => ['a']]);
    }
}

final class QueueHardeningBranchBNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['items' => ['b']]);
    }
}
