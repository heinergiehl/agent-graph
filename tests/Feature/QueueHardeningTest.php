<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Queue\ContinueDelayedGraphJob;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Illuminate\Support\Facades\Queue;

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
