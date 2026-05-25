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

it('allows applications to replace delay scheduling without replacing the runtime', function () {
    Queue::fake();
    RecordingDelayScheduler::$scheduled = [];
    app()->singleton(DelayScheduler::class, RecordingDelayScheduler::class);

    AgentGraph::define(
        StateGraph::make('custom_delay_scheduler_graph')
            ->state(['ready' => 'bool|null'])
            ->node('wait', DelayNode::class)
            ->edge(StateGraph::START, 'wait')
            ->edge('wait', StateGraph::END)
    );

    $run = AgentGraph::graph('custom_delay_scheduler_graph')
        ->thread('delay-thread')
        ->input([])
        ->meta(['workflow_run_id' => 123])
        ->run();

    expect($run->status())->toBe('delayed')
        ->and(RecordingDelayScheduler::$scheduled)->toHaveCount(1)
        ->and(RecordingDelayScheduler::$scheduled[0]['run_id'])->toBe($run->runId())
        ->and(RecordingDelayScheduler::$scheduled[0]['payload']['interrupt_id'] ?? null)->toBe($run->interrupt()['interrupt_id'])
        ->and(RecordingDelayScheduler::$scheduled[0]['payload']['run_meta']['workflow_run_id'] ?? null)->toBe(123)
        ->and(RecordingDelayScheduler::$scheduled[0]['resume_at'])->toBeInstanceOf(DateTimeInterface::class);

    Queue::assertNotPushed(ContinueDelayedGraphJob::class);
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
    /** @var array<int, array{run_id: string, payload: array<string, mixed>, resume_at: DateTimeInterface}> */
    public static array $scheduled = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function schedule(string $runId, array $payload, DateTimeInterface $resumeAt): void
    {
        self::$scheduled[] = [
            'run_id' => $runId,
            'payload' => $payload,
            'resume_at' => $resumeAt,
        ];
    }
}
