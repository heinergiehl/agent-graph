<?php

use Heiner\AgentGraph\Contracts\DelayScheduler;
use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Queue\ContinueDelayedGraphJob;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Illuminate\Support\Facades\Queue;

it('marks delay interrupts as delayed runs with resume metadata', function () {
    Queue::fake();

    AgentGraph::define(
        StateGraph::make('delay_graph')
            ->state(['ready' => 'bool|null'])
            ->node('wait', DelayNode::class)
            ->edge(StateGraph::START, 'wait')
            ->edge('wait', StateGraph::END)
    );

    $run = AgentGraph::graph('delay_graph')->thread('delay-thread')->input([])->run();

    expect($run->status())->toBe('delayed')
        ->and($run->interrupt()['type'])->toBe('delay');

    Queue::assertPushed(ContinueDelayedGraphJob::class, function (ContinueDelayedGraphJob $job) use ($run): bool {
        return $job->runId === $run->runId()
            && ($job->payload['interrupt_id'] ?? null) === $run->interrupt()['interrupt_id']
            && $job->delay instanceof DateTimeInterface;
    });
});

it('schedules delay interrupts through the configured scheduler contract', function () {
    $scheduler = new RecordingDelayScheduler;
    app()->instance(DelayScheduler::class, $scheduler);

    AgentGraph::define(
        StateGraph::make('delay_scheduler_graph')
            ->state(['ready' => 'bool|null'])
            ->node('wait', DelayNode::class)
            ->edge(StateGraph::START, 'wait')
            ->edge('wait', StateGraph::END)
    );

    $run = AgentGraph::graph('delay_scheduler_graph')->thread('delay-scheduler-thread')->input([])->run();

    expect($run->status())->toBe('delayed')
        ->and($scheduler->scheduled)->toHaveCount(1)
        ->and($scheduler->scheduled[0]['run_id'])->toBe($run->runId())
        ->and($scheduler->scheduled[0]['interrupt_id'])->toBe($run->interrupt()['interrupt_id'])
        ->and($scheduler->scheduled[0]['resume_at'])->toBeInstanceOf(DateTimeInterface::class)
        ->and($scheduler->scheduled[0]['payload'])->toBe(['interrupt_id' => $run->interrupt()['interrupt_id']]);
});

final class DelayNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::interrupt('delay', ['resume_at' => now()->addMinute()->toISOString()]);
    }
}

final class RecordingDelayScheduler implements DelayScheduler
{
    public array $scheduled = [];

    public function schedule(string $runId, string $interruptId, DateTimeInterface $resumeAt, array $payload = []): void
    {
        $this->scheduled[] = [
            'run_id' => $runId,
            'interrupt_id' => $interruptId,
            'resume_at' => $resumeAt,
            'payload' => $payload,
        ];
    }
}
