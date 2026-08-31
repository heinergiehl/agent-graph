<?php

use Carbon\CarbonImmutable;
use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Contracts\CheckpointStore;
use Heiner\AgentGraph\Contracts\DelayScheduler;
use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Contracts\MemoryStore;
use Heiner\AgentGraph\Contracts\NodeExecutionStore;
use Heiner\AgentGraph\Contracts\RunStore;
use Heiner\AgentGraph\Contracts\TaskStore;
use Heiner\AgentGraph\Contracts\TraceStore;
use Heiner\AgentGraph\Contracts\WriteStore;
use Heiner\AgentGraph\Events\GraphCheckpointCreated;
use Heiner\AgentGraph\Events\GraphInterrupted;
use Heiner\AgentGraph\Events\GraphNodeCompleted;
use Heiner\AgentGraph\Graph\GraphDefinition;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Persistence\DatabaseCheckpointStore;
use Heiner\AgentGraph\Persistence\DatabaseInterruptStore;
use Heiner\AgentGraph\Persistence\DatabaseMemoryStore;
use Heiner\AgentGraph\Persistence\DatabaseNodeExecutionStore;
use Heiner\AgentGraph\Persistence\DatabaseRunStore;
use Heiner\AgentGraph\Persistence\DatabaseTaskStore;
use Heiner\AgentGraph\Persistence\DatabaseTraceStore;
use Heiner\AgentGraph\Persistence\DatabaseWriteStore;
use Heiner\AgentGraph\Queue\ContinueDelayedGraphJob;
use Heiner\AgentGraph\Runtime\GraphRuntime;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunEventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();

    // The service provider normally substitutes memory stores in tests. These
    // recovery tests must reload durable rows through fresh store instances.
    foreach ([
        RunStore::class => DatabaseRunStore::class,
        CheckpointStore::class => DatabaseCheckpointStore::class,
        WriteStore::class => DatabaseWriteStore::class,
        TaskStore::class => DatabaseTaskStore::class,
        InterruptStore::class => DatabaseInterruptStore::class,
        MemoryStore::class => DatabaseMemoryStore::class,
        NodeExecutionStore::class => DatabaseNodeExecutionStore::class,
        TraceStore::class => DatabaseTraceStore::class,
    ] as $contract => $implementation) {
        app()->bind($contract, $implementation);
    }

    $this->travelTo(CarbonImmutable::parse('2026-08-31T12:00:00Z'));
    DelayRecoveryNode::$invocations = 0;
    Queue::fake();
});

afterEach(function () {
    $this->travelBack();
});

it('redelivers a committed delay after its scheduler throws without replaying the wait', function (string $mode) {
    config(['agent-graph.execution.mode' => $mode]);
    $scheduler = new DelayRecoveryRecordingScheduler;
    $scheduler->fail = true;
    app()->instance(DelayScheduler::class, $scheduler);
    $graph = delayRecoveryGraph();
    $manager = delayRecoveryManager($graph);

    expect(function () use ($manager, $mode) {
        $result = $manager->graph('delay_recovery')->thread('dispatch-failure')->run();

        if ($mode === 'queued_supersteps') {
            $execution = app(NodeExecutionStore::class)->listForRun($result->runId())[0];
            $manager->executeQueuedNode($execution['execution_id']);
            $manager->continueQueuedSuperstep($result->runId(), 1);
        }
    })->toThrow(RuntimeException::class, 'Injected delay dispatch failure.');

    $run = app(RunStore::class)->list(['graph_key' => 'delay_recovery'])[0];
    $runId = $run['public_id'];
    $before = delayRecoveryRecords($runId);
    $interrupt = app(InterruptStore::class)->pendingForRun($runId);

    expect($run['status'])->toBe('delayed')
        ->and($interrupt['checkpoint_id'])->toBe($run['current_checkpoint_id'])
        ->and(DelayRecoveryNode::$invocations)->toBe(1);

    $this->travel(2)->minutes();
    $replacement = new DelayRecoveryRecordingScheduler;
    app()->instance(DelayScheduler::class, $replacement);
    $freshManager = delayRecoveryManager($graph);
    $recovered = $freshManager->recover($runId);

    expect($replacement->scheduled)->toBe([[
        'run_id' => $runId,
        'payload' => ['interrupt_id' => $interrupt['interrupt_id']],
        'resume_at' => $interrupt['payload']['resume_at'],
    ]])
        ->and($recovered->status())->toBe('delayed')
        ->and($recovered->interrupt()['interrupt_id'])->toBe($interrupt['interrupt_id'])
        ->and(delayRecoveryRecords($runId))->toBe($before)
        ->and(DelayRecoveryNode::$invocations)->toBe(1);
    Queue::assertNotPushed(ContinueDelayedGraphJob::class);
})->with(['sync', 'queued_supersteps']);

it('redelivers lost queue jobs with their original due time and tolerates duplicate delivery', function (int $elapsedMinutes) {
    config([
        'agent-graph.execution.queue_connection' => 'delay-connection',
        'agent-graph.execution.queue' => 'delay-queue',
    ]);
    $graph = delayRecoveryGraph();
    $manager = delayRecoveryManager($graph);
    $waiting = $manager->graph('delay_recovery')->thread('lost-queue-job')->run();
    $before = delayRecoveryRecords($waiting->runId());
    $due = $waiting->interrupt()['payload']['resume_at'];
    Queue::assertPushed(ContinueDelayedGraphJob::class, 1);

    // The queue accepted the first job, but no worker will receive it.
    Queue::fake();
    Event::fake([GraphCheckpointCreated::class, GraphInterrupted::class, GraphNodeCompleted::class]);
    $this->travel($elapsedMinutes)->minutes();
    $freshManager = delayRecoveryManager($graph);
    $freshManager->recover($waiting->runId());
    $freshManager->recover($waiting->runId());

    Queue::assertPushed(ContinueDelayedGraphJob::class, 2);
    $jobs = Queue::pushed(ContinueDelayedGraphJob::class);

    foreach ($jobs as $job) {
        expect($job->runId)->toBe($waiting->runId())
            ->and($job->payload)->toBe(['interrupt_id' => $waiting->interrupt()['interrupt_id']])
            ->and(CarbonImmutable::instance($job->delay)->toJSON())->toBe($due)
            ->and($job->connection)->toBe('delay-connection')
            ->and($job->queue)->toBe('delay-queue');
    }

    expect(delayRecoveryRecords($waiting->runId()))->toBe($before)
        ->and(DelayRecoveryNode::$invocations)->toBe(1);
    Event::assertNotDispatched(GraphCheckpointCreated::class);
    Event::assertNotDispatched(GraphInterrupted::class);
    Event::assertNotDispatched(GraphNodeCompleted::class);

    $this->travelTo(CarbonImmutable::parse($due)->addMinute());
    $first = $jobs[0]->handle($freshManager);
    $completed = delayRecoveryRecords($waiting->runId());
    $second = $jobs[1]->handle($freshManager);

    expect($first->status())->toBe('completed')
        ->and($first->state('done'))->toBeTrue()
        ->and($second->status())->toBe('completed')
        ->and(delayRecoveryRecords($waiting->runId()))->toBe($completed)
        ->and(DelayRecoveryNode::$invocations)->toBe(2);
})->with(['before due' => 2, 'at due' => 5, 'after due' => 10]);

it('does not let an old redelivered job resume a newer delay', function () {
    $graph = StateGraph::make('successive_delays')
        ->state(['phase' => 'int', 'done' => 'bool|null'])
        ->node('wait', fn (NodeContext $context): NodeResult => $context->state('phase') < 2
            ? NodeResult::interrupt('delay', ['resume_at' => now()->addMinutes(5)->toISOString()], ['phase' => $context->state('phase') + 1])
            : NodeResult::end(['done' => true]))
        ->edge(StateGraph::START, 'wait')
        ->edge('wait', StateGraph::END)
        ->compile();
    $manager = delayRecoveryManager($graph);
    $first = $manager->graph('successive_delays')->thread('successive-delays')->input(['phase' => 0])->run();
    Queue::fake();
    $manager->recover($first->runId());
    $oldJob = Queue::pushed(ContinueDelayedGraphJob::class)->first();

    $this->travel(5)->minutes();
    $second = $oldJob->handle($manager);
    $before = delayRecoveryRecords($first->runId());
    expect($second->status())->toBe('delayed')
        ->and($second->interrupt()['interrupt_id'])->not->toBe($first->interrupt()['interrupt_id']);

    Queue::fake();
    $stale = $oldJob->handle($manager);
    expect($stale->interrupt()['interrupt_id'])->toBe($second->interrupt()['interrupt_id'])
        ->and(delayRecoveryRecords($first->runId()))->toBe($before);
    Queue::assertNothingPushed();

    $manager->recover($first->runId());
    $currentJob = Queue::pushed(ContinueDelayedGraphJob::class)->sole();
    expect($currentJob->payload)->toBe(['interrupt_id' => $second->interrupt()['interrupt_id']]);
    $this->travel(5)->minutes();
    expect($currentJob->handle($manager)->state('done'))->toBeTrue();
});

it('keeps the durable delay recoverable when redelivery fails again', function () {
    $scheduler = new DelayRecoveryRecordingScheduler;
    app()->instance(DelayScheduler::class, $scheduler);
    $manager = delayRecoveryManager(delayRecoveryGraph());
    $waiting = $manager->graph('delay_recovery')->thread('repeated-outage')->run();
    $before = delayRecoveryRecords($waiting->runId());
    $scheduler->fail = true;

    expect(fn () => $manager->recover($waiting->runId()))
        ->toThrow(RuntimeException::class, 'Injected delay dispatch failure.');
    expect(delayRecoveryRecords($waiting->runId()))->toBe($before);

    $scheduler->fail = false;
    $manager->recover($waiting->runId());

    expect($scheduler->scheduled)->toHaveCount(3)
        ->and($scheduler->scheduled[2])->toBe($scheduler->scheduled[0])
        ->and(delayRecoveryRecords($waiting->runId()))->toBe($before);
});

it('does not schedule ordinary input waits or terminal runs', function (string $status) {
    $graph = $status === 'interrupted'
        ? StateGraph::make('delay_recovery')->node('ask', fn () => NodeResult::interrupt('input', ['prompt' => 'Approve?']))
            ->edge(StateGraph::START, 'ask')->compile()
        : delayRecoveryGraph();
    $manager = delayRecoveryManager($graph);
    $waiting = $manager->graph('delay_recovery')->thread('non-delay-recovery')->run();

    if ($status === 'cancelled') {
        $manager->cancel($waiting->runId());
    } elseif ($status !== 'interrupted') {
        app(RunStore::class)->update($waiting->runId(), ['status' => $status]);
    }

    $before = delayRecoveryRecords($waiting->runId());
    Queue::fake();
    $recovered = delayRecoveryManager($graph)->recover($waiting->runId());

    expect($recovered->status())->toBe($status)
        ->and(delayRecoveryRecords($waiting->runId()))->toBe($before);
    Queue::assertNothingPushed();
})->with(['interrupted', 'completed', 'failed', 'cancelled']);

it('refuses redelivery when durable delay authority is inconsistent', function (string $damage) {
    $manager = delayRecoveryManager(delayRecoveryGraph());
    $waiting = $manager->graph('delay_recovery')->thread('inconsistent-delay')->run();
    $runId = $waiting->runId();
    $interrupt = $waiting->interrupt();

    $changed = match ($damage) {
        'missing_interrupt' => app(InterruptStore::class)->resolvePending($interrupt['interrupt_id'], $runId, []),
        'wrong_type' => DB::table('agent_graph_interrupts')->where('interrupt_id', $interrupt['interrupt_id'])->update(['type' => 'input']),
        'foreign_checkpoint' => DB::table('agent_graph_interrupts')->where('interrupt_id', $interrupt['interrupt_id'])->update(['checkpoint_id' => 'foreign-checkpoint']),
        'stale_current_checkpoint' => app(RunStore::class)->update($runId, ['current_checkpoint_id' => 'older-checkpoint']),
        'missing_time' => DB::table('agent_graph_interrupts')->where('interrupt_id', $interrupt['interrupt_id'])->update(['payload' => json_encode([])]),
        'invalid_time' => DB::table('agent_graph_interrupts')->where('interrupt_id', $interrupt['interrupt_id'])->update(['payload' => json_encode(['resume_at' => 'not-a-timestamp'])]),
        'relative_time' => DB::table('agent_graph_interrupts')->where('interrupt_id', $interrupt['interrupt_id'])->update(['payload' => json_encode(['resume_at' => 'tomorrow'])]),
        'wrong_wait_node' => DB::table('agent_graph_checkpoints')->where('checkpoint_id', $interrupt['checkpoint_id'])->update(['meta' => json_encode(['runtime' => ['wait' => ['type' => 'delay', 'node_id' => 'another-node']]])]),
        'wrong_checkpoint_version' => DB::table('agent_graph_checkpoints')->where('checkpoint_id', $interrupt['checkpoint_id'])->update(['graph_version' => '2']),
    };

    if (is_int($changed)) {
        expect($changed)->toBe(1);
    }

    $before = delayRecoveryRecords($runId);
    Queue::fake();

    expect(fn () => $manager->recover($runId))->toThrow(RuntimeException::class);
    expect(delayRecoveryRecords($runId))->toBe($before);
    Queue::assertNothingPushed();
})->with(['missing_interrupt', 'wrong_type', 'foreign_checkpoint', 'stale_current_checkpoint', 'missing_time', 'invalid_time', 'relative_time', 'wrong_wait_node', 'wrong_checkpoint_version']);

it('requires the saved graph version before redelivering a delay', function (bool $missingGraph) {
    $manager = delayRecoveryManager(delayRecoveryGraph());
    $waiting = $manager->graph('delay_recovery')->thread('graph-binding')->run();
    $freshManager = delayRecoveryManager($missingGraph ? null : delayRecoveryGraph(version: '2'));
    $before = delayRecoveryRecords($waiting->runId());
    Queue::fake();

    expect(fn () => $freshManager->recover($waiting->runId()))->toThrow(RuntimeException::class);
    expect(delayRecoveryRecords($waiting->runId()))->toBe($before);
    Queue::assertNothingPushed();
})->with([true, false]);

it('does not infer or rewrite a missing legacy wait marker', function () {
    $manager = delayRecoveryManager(delayRecoveryGraph());
    $waiting = $manager->graph('delay_recovery')->thread('legacy-wait-marker')->run();
    $checkpoint = app(CheckpointStore::class)->latestForRun($waiting->runId());
    $meta = $checkpoint['meta'];
    unset($meta['runtime']['wait']);
    DB::table('agent_graph_checkpoints')->where('checkpoint_id', $checkpoint['checkpoint_id'])
        ->update(['meta' => json_encode($meta)]);
    $before = delayRecoveryRecords($waiting->runId());
    Queue::fake();

    expect(fn () => $manager->recover($waiting->runId()))
        ->toThrow(RuntimeException::class, 'legacy or inconsistent waits require reconciliation');
    expect(delayRecoveryRecords($waiting->runId()))->toBe($before);
    Queue::assertNothingPushed();
});

function delayRecoveryGraph(string $version = '1'): GraphDefinition
{
    return StateGraph::make('delay_recovery', $version)
        ->state(['waiting' => 'bool|null', 'done' => 'bool|null'])
        ->node('wait', DelayRecoveryNode::class)
        ->edge(StateGraph::START, 'wait')
        ->edge('wait', StateGraph::END)
        ->compile();
}

function delayRecoveryManager(?GraphDefinition $graph = null): AgentGraphManager
{
    $manager = new AgentGraphManager(app()->build(GraphRuntime::class), app(RunEventDispatcher::class));

    if ($graph !== null) {
        $manager->define($graph);
    }

    return $manager;
}

function delayRecoveryRecords(string $runId): array
{
    return [
        'run' => app(RunStore::class)->find($runId),
        'checkpoints' => app(CheckpointStore::class)->listForRun($runId),
        'interrupts' => app(InterruptStore::class)->listForRun($runId),
        'writes' => app(WriteStore::class)->listForRun($runId),
        'executions' => app(NodeExecutionStore::class)->listForRun($runId),
    ];
}

final class DelayRecoveryNode
{
    public static int $invocations = 0;

    public function __invoke(NodeContext $context): NodeResult
    {
        self::$invocations++;

        if ($context->state('waiting') === true) {
            return NodeResult::end(['done' => true]);
        }

        return NodeResult::interrupt('delay', ['resume_at' => now()->addMinutes(5)->toISOString()], ['waiting' => true]);
    }
}

final class DelayRecoveryRecordingScheduler implements DelayScheduler
{
    public array $scheduled = [];

    public bool $fail = false;

    public function schedule(string $runId, array $payload, DateTimeInterface $resumeAt): void
    {
        $this->scheduled[] = [
            'run_id' => $runId,
            'payload' => $payload,
            'resume_at' => CarbonImmutable::instance($resumeAt)->toJSON(),
        ];

        if ($this->fail) {
            throw new RuntimeException('Injected delay dispatch failure.');
        }
    }
}
