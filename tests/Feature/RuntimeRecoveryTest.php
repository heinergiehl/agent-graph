<?php

use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Contracts\CheckpointStore;
use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Contracts\NodeExecutionStore;
use Heiner\AgentGraph\Contracts\RunStore;
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
use Heiner\AgentGraph\Runtime\GraphRuntime;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunEventDispatcher;
use Heiner\AgentGraph\Runtime\RunResult;
use Heiner\AgentGraph\Runtime\RuntimeOptions;
use Heiner\AgentGraph\Support\DelaySchedulerResolver;
use Illuminate\Database\DatabaseManager;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
});

it('recovers a resume accepted before continuation could start', function () {
    [$manager, $runtime, $runs, $checkpoints, $interrupts] = runtimeRecoveryManager(runtimeClass: RuntimeRecoveryCrashBeforeContinuationRuntime::class);
    $manager->define(runtimeRecoveryInputGraph('recover_accepted_resume'));

    $interrupted = $manager->graph('recover_accepted_resume')->thread('recover-resume')->run();
    $runtime->crashBeforeContinuation = true;

    expect(fn () => $manager->resume($interrupted->runId(), [
        'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
        'answer' => 'Ada',
    ]))->toThrow(RuntimeException::class, 'Injected crash before continuation.');

    $storedRun = $runs->find($interrupted->runId());

    expect($storedRun['status'])->toBe('running')
        ->and($interrupts->pendingForRun($interrupted->runId()))->toBeNull()
        ->and(data_get($storedRun, 'meta.runtime.recovery.pending_resume.interrupt_id'))
        ->toBe($interrupted->interrupt()['interrupt_id']);

    expect(fn () => $manager->resume($interrupted->runId(), [
        'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
        'answer' => 'Grace',
    ]))->toThrow(InvalidArgumentException::class, 'has no pending interrupt');

    $recovered = $manager->resume($interrupted->runId(), [
        'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
        'answer' => 'Ada',
    ]);

    expect($recovered->status())->toBe('completed')
        ->and($recovered->state('answer'))->toBe('Ada')
        ->and(data_get($runs->find($interrupted->runId()), 'meta.runtime.recovery.pending_resume'))
        ->toBeNull();
});

it('recovers an accepted state edit before continuation could start', function () {
    [$manager, $runtime, $runs, $checkpoints, $interrupts] = runtimeRecoveryManager(runtimeClass: RuntimeRecoveryCrashBeforeContinuationRuntime::class);
    $manager->define(runtimeRecoveryStateEditGraph('recover_accepted_state_edit'));

    $interrupted = $manager->graph('recover_accepted_state_edit')
        ->thread('recover-state-edit')
        ->input(['draft' => null])
        ->run();

    if (! $runtime instanceof RuntimeRecoveryCrashBeforeContinuationRuntime) {
        throw new RuntimeException('Expected crash-injection runtime.');
    }

    $runtime->crashBeforeContinuation = true;

    expect(fn () => $manager->resumeWithStateEdit(
        $interrupted->runId(),
        $interrupted->interrupt()['interrupt_id'],
        ['draft' => 'approved copy'],
        'reviewer-1',
    ))->toThrow(RuntimeException::class, 'Injected crash before continuation.');

    expect(data_get($runs->find($interrupted->runId()), 'meta.runtime.recovery.pending_resume.kind'))
        ->toBe('state_edit')
        ->and($interrupts->pendingForRun($interrupted->runId()))
        ->toBeNull();

    $recovered = $manager->resumeWithStateEdit(
        $interrupted->runId(),
        $interrupted->interrupt()['interrupt_id'],
        ['draft' => 'approved copy'],
        'reviewer-1',
    );

    expect($recovered->status())->toBe('completed')
        ->and($recovered->state('approved'))->toBeTrue();
});

it('does not mutate a run that is still waiting on a pending interrupt', function () {
    [$manager, $runtime, $runs, $checkpoints, $interrupts] = runtimeRecoveryManager();
    $manager->define(runtimeRecoveryInputGraph('waiting_run_is_not_recovered'));

    $interrupted = $manager->graph('waiting_run_is_not_recovered')->thread('waiting-recovery')->run();
    $recovered = $manager->recover($interrupted->runId());

    expect($recovered->status())->toBe('interrupted')
        ->and($recovered->interrupt()['interrupt_id'])->toBe($interrupted->interrupt()['interrupt_id'])
        ->and($interrupts->pendingForRun($interrupted->runId())['interrupt_id'])
        ->toBe($interrupted->interrupt()['interrupt_id']);
});

it('rolls back interrupt resolution when the atomic resume transition crashes', function () {
    $runs = new RuntimeRecoveryCrashOnRunningRunStore(app(DatabaseManager::class));
    [$manager, $runtime, $runs, $checkpoints, $interrupts] = runtimeRecoveryManager(runs: $runs);
    $manager->define(runtimeRecoveryInputGraph('rollback_resume_transition'));

    $interrupted = $manager->graph('rollback_resume_transition')->thread('rollback-resume')->run();
    $checkpointCount = count($checkpoints->listForRun($interrupted->runId()));
    $runs->crashOnRunningUpdate = true;

    expect(fn () => $manager->resume($interrupted->runId(), [
        'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
        'answer' => 'Ada',
    ]))->toThrow(RuntimeException::class, 'Injected crash during running transition.');

    expect($runs->find($interrupted->runId())['status'])->toBe('interrupted')
        ->and($interrupts->pendingForRun($interrupted->runId())['interrupt_id'])
        ->toBe($interrupted->interrupt()['interrupt_id'])
        ->and(count($checkpoints->listForRun($interrupted->runId())))
        ->toBe($checkpointCount);
});

it('finishes from a durable checkpoint without rerunning the resumed node', function () {
    $runs = new RuntimeRecoveryCrashOnCompletedRunStore(app(DatabaseManager::class));
    [$manager, $runtime, $runs, $checkpoints] = runtimeRecoveryManager(runs: $runs);
    $manager->define(runtimeRecoveryInputGraph('recover_after_checkpoint'));

    RuntimeRecoveryInputNode::$resumeInvocations = 0;
    $interrupted = $manager->graph('recover_after_checkpoint')->thread('recover-after-checkpoint')->run();
    $runs->crashOnCompletedUpdate = true;

    expect(fn () => $manager->resume($interrupted->runId(), [
        'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
        'answer' => 'Ada',
    ]))->toThrow(RuntimeException::class, 'Injected crash during completed transition.');

    $checkpoint = $checkpoints->latestForRun($interrupted->runId());

    expect($runs->find($interrupted->runId())['status'])->toBe('running')
        ->and($checkpoint['state']['answer'])->toBe('Ada')
        ->and($checkpoint['next_nodes'])->toBe([])
        ->and(data_get($runs->find($interrupted->runId()), 'meta.runtime.recovery.pending_resume'))
        ->toBeNull()
        ->and(RuntimeRecoveryInputNode::$resumeInvocations)->toBe(1);

    $runs->crashOnCompletedUpdate = false;
    $recovered = $manager->recover($interrupted->runId());

    expect($recovered->status())->toBe('completed')
        ->and(RuntimeRecoveryInputNode::$resumeInvocations)->toBe(1);
});

it('rolls back cancellation when pending interrupt finalization crashes', function () {
    $interrupts = new RuntimeRecoveryCrashOnCancelInterruptStore(app(DatabaseManager::class));
    [$manager, $runtime, $runs] = runtimeRecoveryManager(interrupts: $interrupts);
    $manager->define(runtimeRecoveryInputGraph('rollback_cancel_transition'));

    $interrupted = $manager->graph('rollback_cancel_transition')->thread('rollback-cancel')->run();
    $interrupts->crashOnCancelResolution = true;

    expect(fn () => $manager->cancel($interrupted->runId(), ['reason' => 'operator_cancelled']))
        ->toThrow(RuntimeException::class, 'Injected crash during cancel interrupt resolution.');

    expect($runs->find($interrupted->runId())['status'])->toBe('interrupted')
        ->and($interrupts->pendingForRun($interrupted->runId())['interrupt_id'])
        ->toBe($interrupted->interrupt()['interrupt_id']);
});

it('rolls back queued resume scheduling and recovers from the durable resume marker', function () {
    config([
        'agent-graph.execution.mode' => 'sync',
        'queue.default' => 'sync',
    ]);

    $executions = new RuntimeRecoveryCrashOnScheduleNodeExecutionStore(app(DatabaseManager::class));
    [$manager, $runtime, $runs] = runtimeRecoveryManager(nodeExecutions: $executions);
    $manager->define(runtimeRecoveryInputGraph('recover_queued_frontier'));

    $interrupted = $manager->graph('recover_queued_frontier')->thread('recover-queued')->run();
    config(['agent-graph.execution.mode' => 'queued_supersteps']);
    $executions->crashAfterSchedule = true;

    expect(fn () => $manager->resume($interrupted->runId(), [
        'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
        'answer' => 'Ada',
    ]))->toThrow(RuntimeException::class, 'Injected crash during queued scheduling.');

    expect($executions->listForRun($interrupted->runId()))->toBe([])
        ->and(data_get($runs->find($interrupted->runId()), 'meta.runtime.recovery.pending_resume.interrupt_id'))
        ->toBe($interrupted->interrupt()['interrupt_id']);

    $executions->crashAfterSchedule = false;
    $recovered = $manager->recover($interrupted->runId());

    expect($recovered->status())->toBeIn(['running', 'completed'])
        ->and($executions->listForRun($interrupted->runId()))->toHaveCount(1);
});

it('redrives durable queued executions when dispatch crashes after commit', function () {
    config([
        'agent-graph.execution.mode' => 'sync',
        'queue.default' => 'sync',
    ]);

    $executions = new DatabaseNodeExecutionStore(app(DatabaseManager::class));
    [$manager, $runtime, $runs] = runtimeRecoveryManager(
        nodeExecutions: $executions,
        runtimeClass: RuntimeRecoveryCrashBeforeDispatchRuntime::class,
    );
    $manager->define(runtimeRecoveryInputGraph('recover_committed_queued_dispatch'));

    $interrupted = $manager->graph('recover_committed_queued_dispatch')->thread('recover-dispatch')->run();
    config(['agent-graph.execution.mode' => 'queued_supersteps']);

    if (! $runtime instanceof RuntimeRecoveryCrashBeforeDispatchRuntime) {
        throw new RuntimeException('Expected dispatch crash-injection runtime.');
    }

    $runtime->crashBeforeDispatch = true;

    expect(fn () => $manager->resume($interrupted->runId(), [
        'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
        'answer' => 'Ada',
    ]))->toThrow(RuntimeException::class, 'Injected crash before queued dispatch.');

    expect($executions->listForRun($interrupted->runId()))->toHaveCount(1)
        ->and(data_get($runs->find($interrupted->runId()), 'meta.runtime.recovery.pending_resume'))
        ->toBeNull()
        ->and($runtime->dispatches)->toBe(1);

    $runtime->crashBeforeDispatch = false;
    $manager->recover($interrupted->runId());

    expect($runtime->dispatches)->toBe(2);
});

function runtimeRecoveryInputGraph(string $key): GraphDefinition
{
    return StateGraph::make($key)
        ->state(['answer' => 'string|null'])
        ->node('ask', RuntimeRecoveryInputNode::class)
        ->edge(StateGraph::START, 'ask')
        ->edge('ask', StateGraph::END)
        ->compile();
}

function runtimeRecoveryStateEditGraph(string $key): GraphDefinition
{
    return StateGraph::make($key)
        ->state(['draft' => 'string|null', 'approved' => 'bool|null'])
        ->node('review', RuntimeRecoveryStateEditNode::class)
        ->edge(StateGraph::START, 'review')
        ->edge('review', StateGraph::END)
        ->compile();
}

/**
 * @return array{AgentGraphManager, GraphRuntime, RunStore, CheckpointStore, InterruptStore, NodeExecutionStore}
 */
function runtimeRecoveryManager(
    ?RunStore $runs = null,
    ?InterruptStore $interrupts = null,
    ?NodeExecutionStore $nodeExecutions = null,
    string $runtimeClass = GraphRuntime::class,
): array {
    $db = app(DatabaseManager::class);
    $runs ??= new DatabaseRunStore($db);
    $checkpoints = new DatabaseCheckpointStore($db);
    $interrupts ??= new DatabaseInterruptStore($db);
    $nodeExecutions ??= new DatabaseNodeExecutionStore($db);

    /** @var GraphRuntime $runtime */
    $runtime = new $runtimeClass(
        container: app(),
        runs: $runs,
        checkpoints: $checkpoints,
        writes: new DatabaseWriteStore($db),
        tasks: new DatabaseTaskStore($db),
        interrupts: $interrupts,
        memory: new DatabaseMemoryStore($db),
        traces: new DatabaseTraceStore($db),
        locks: app(LockProvider::class),
        delaySchedulers: app(DelaySchedulerResolver::class),
        events: app(RunEventDispatcher::class),
        nodeExecutions: $nodeExecutions,
    );

    return [
        new AgentGraphManager($runtime, app(RunEventDispatcher::class)),
        $runtime,
        $runs,
        $checkpoints,
        $interrupts,
        $nodeExecutions,
    ];
}

final class RuntimeRecoveryInputNode
{
    public static int $resumeInvocations = 0;

    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->hasResumePayload()) {
            self::$resumeInvocations++;

            return NodeResult::end(['answer' => (string) $context->state('answer')]);
        }

        return NodeResult::interrupt('input', ['prompt' => 'What is your name?']);
    }
}

final class RuntimeRecoveryStateEditNode
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->state('draft') === null) {
            return NodeResult::interrupt('state_edit', ['title' => 'Edit draft']);
        }

        return NodeResult::end(['approved' => true]);
    }
}

final class RuntimeRecoveryCrashBeforeContinuationRuntime extends GraphRuntime
{
    public bool $crashBeforeContinuation = false;

    protected function continueLocked(
        GraphDefinition $graph,
        array $run,
        array $state,
        array $nextNodes,
        array $resumeContext = [],
        ?RuntimeOptions $options = null,
    ): RunResult {
        if ($this->crashBeforeContinuation && array_key_exists('resume_payload', $resumeContext)) {
            $this->crashBeforeContinuation = false;

            throw new RuntimeException('Injected crash before continuation.');
        }

        return parent::continueLocked($graph, $run, $state, $nextNodes, $resumeContext, $options);
    }
}

final class RuntimeRecoveryCrashOnRunningRunStore extends DatabaseRunStore
{
    public bool $crashOnRunningUpdate = false;

    public function update(string $runId, array $attributes): array
    {
        $run = parent::update($runId, $attributes);

        if ($this->crashOnRunningUpdate && ($attributes['status'] ?? null) === 'running') {
            throw new RuntimeException('Injected crash during running transition.');
        }

        return $run;
    }
}

final class RuntimeRecoveryCrashOnCompletedRunStore extends DatabaseRunStore
{
    public bool $crashOnCompletedUpdate = false;

    public function update(string $runId, array $attributes): array
    {
        $run = parent::update($runId, $attributes);

        if ($this->crashOnCompletedUpdate && ($attributes['status'] ?? null) === 'completed') {
            throw new RuntimeException('Injected crash during completed transition.');
        }

        return $run;
    }
}

final class RuntimeRecoveryCrashBeforeDispatchRuntime extends GraphRuntime
{
    public bool $crashBeforeDispatch = false;

    public int $dispatches = 0;

    protected function dispatchNodeExecution(string $executionId): void
    {
        $this->dispatches++;

        if ($this->crashBeforeDispatch) {
            throw new RuntimeException('Injected crash before queued dispatch.');
        }

        parent::dispatchNodeExecution($executionId);
    }
}

final class RuntimeRecoveryCrashOnCancelInterruptStore extends DatabaseInterruptStore
{
    public bool $crashOnCancelResolution = false;

    public function resolvePending(
        string $interruptId,
        string $runId,
        array $response,
        ?string $resolvedBy = null,
    ): array {
        $interrupt = parent::resolvePending($interruptId, $runId, $response, $resolvedBy);

        if ($this->crashOnCancelResolution && ($response['type'] ?? null) === 'cancelled') {
            throw new RuntimeException('Injected crash during cancel interrupt resolution.');
        }

        return $interrupt;
    }
}

final class RuntimeRecoveryCrashOnScheduleNodeExecutionStore extends DatabaseNodeExecutionStore
{
    public bool $crashAfterSchedule = false;

    public function schedule(array $execution): array
    {
        $scheduled = parent::schedule($execution);

        if ($this->crashAfterSchedule) {
            throw new RuntimeException('Injected crash during queued scheduling.');
        }

        return $scheduled;
    }
}
