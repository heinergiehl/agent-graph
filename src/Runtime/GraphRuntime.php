<?php

namespace Heiner\AgentGraph\Runtime;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Heiner\AgentGraph\Contracts\CheckpointStore;
use Heiner\AgentGraph\Contracts\DelayScheduler;
use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Contracts\MemoryStore;
use Heiner\AgentGraph\Contracts\NodeExecutionStore;
use Heiner\AgentGraph\Contracts\RunStore;
use Heiner\AgentGraph\Contracts\TaskStore;
use Heiner\AgentGraph\Contracts\TraceStore;
use Heiner\AgentGraph\Contracts\WriteStore;
use Heiner\AgentGraph\Events\GraphCheckpointCreated;
use Heiner\AgentGraph\Events\GraphEvent;
use Heiner\AgentGraph\Events\GraphInterrupted;
use Heiner\AgentGraph\Events\GraphNodeCompleted;
use Heiner\AgentGraph\Events\GraphNodeFailed;
use Heiner\AgentGraph\Events\GraphNodeStarted;
use Heiner\AgentGraph\Events\GraphResumed;
use Heiner\AgentGraph\Events\GraphRunCancelled;
use Heiner\AgentGraph\Events\GraphRunCompleted;
use Heiner\AgentGraph\Events\GraphRunFailed;
use Heiner\AgentGraph\Events\GraphRunStarted;
use Heiner\AgentGraph\Exceptions\NodeExecutionClaimLostException;
use Heiner\AgentGraph\Exceptions\RunStateChangedException;
use Heiner\AgentGraph\Graph\GraphDefinition;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Persistence\InMemoryStore;
use Heiner\AgentGraph\Queue\ContinueSuperstepJob;
use Heiner\AgentGraph\Queue\NodeExecutionJob;
use Heiner\AgentGraph\State\Reducer;
use Heiner\AgentGraph\State\StateReducer;
use Heiner\AgentGraph\State\StateSchemaValidator;
use Heiner\AgentGraph\Support\AgentGraphDatabase;
use Heiner\AgentGraph\Support\AgentGraphQueue;
use Heiner\AgentGraph\Support\DelaySchedulerResolver;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Coordinates durable run transitions, receipt schedules and checkpoint commits. */
class GraphRuntime
{
    public function __construct(
        protected Container $container,
        protected RunStore $runs,
        protected CheckpointStore $checkpoints,
        protected WriteStore $writes,
        protected TaskStore $tasks,
        protected InterruptStore $interrupts,
        protected MemoryStore $memory,
        protected TraceStore $traces,
        protected LockProvider $locks,
        protected ?DelaySchedulerResolver $delaySchedulers = null,
        protected ?RunInspector $inspector = null,
        protected ?RunEventDispatcher $events = null,
        protected ?NodeExecutionStore $nodeExecutions = null,
    ) {}

    public function run(GraphDefinition $graph, string $threadId, array $input = [], array $meta = [], RuntimeOptions|array $options = []): RunResult
    {
        return $this->drive($graph, $this->startRun($graph, $threadId, $input, $meta, $options));
    }

    protected function startRun(GraphDefinition $graph, string $threadId, array $input, array $meta, RuntimeOptions|array $options): RunResult|ExecutionFrontier
    {
        $runtimeOptions = RuntimeOptions::from($options);
        $this->assertStatePatchMatchesSchema($graph, $input);
        $meta = $runtimeOptions->applyToMeta($meta);

        $run = $this->runs->create($graph->key(), $graph->version(), $threadId, $input, $meta);
        $this->dispatchRunEvent('run.started', new GraphRunStarted($run['public_id'], $threadId, $graph->key(), payload: ['input' => $input]));

        return $this->prepareContinuation($graph, $run, $input, $graph->entryNodes(), options: $runtimeOptions);
    }

    public function runSession(GraphDefinition $graph, string $threadId, array $input = [], array $meta = [], RuntimeOptions|array $options = []): RunResult
    {
        $next = $this->locks->withLock('agent-graph:session:'.$graph->key().':'.$threadId, function () use ($graph, $threadId, $input, $meta, $options): RunResult|ExecutionFrontier {
            $active = $this->latestForThreadGraph($threadId, $graph->key());

            if ($active !== null) {
                $snapshot = $this->inspect($active['public_id']);

                if ($snapshot !== null) {
                    return $snapshot->toRunResult();
                }
            }

            return $this->startRun($graph, $threadId, $input, $meta, $options);
        });

        return $this->drive($graph, $next);
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    public function resume(string $runId, array $payload, array $graphs, bool $strictKeys = false, RuntimeOptions|array $options = [], bool $validateInterruptContract = false): RunResult
    {
        $next = $this->locks->withLock('agent-graph:run:'.$runId, function () use ($runId, $payload, $graphs, $strictKeys, $validateInterruptContract, $options): RunResult|ExecutionFrontier {
            $run = $this->runs->find($runId) ?? throw new RuntimeException("Run [{$runId}] was not found.");
            $incomingOptions = RuntimeOptions::from($options);
            $runtimeOptions = $incomingOptions->isDefault() ? RuntimeOptions::fromRun($run) : $incomingOptions;
            $this->assertRunCanResume($run);
            $graph = $graphs[$run['graph_key']] ?? throw new RuntimeException("Graph [{$run['graph_key']}] is not defined.");
            $this->assertGraphVersionMatches($run, $graph, 'Run');
            $checkpoint = $this->checkpoints->latestForRun($runId) ?? throw new RuntimeException("Run [{$runId}] has no checkpoint.");
            $interrupt = $this->interrupts->pendingForRun($runId);

            $resumePayload = $payload;
            unset($resumePayload['interrupt_id']);
            $this->assertStatePatchMatchesSchema($graph, $resumePayload, strictKeys: $strictKeys);

            $resumeInterruptId = null;

            if (array_key_exists('interrupt_id', $payload)) {
                if (! is_string($payload['interrupt_id']) || $payload['interrupt_id'] === '') {
                    throw new InvalidArgumentException('interrupt_id must be a non-empty string.');
                }

                $resumeInterruptId = $payload['interrupt_id'];

                if ($interrupt === null && $this->resumeProtocol()->matchesPendingResumeRecovery($run, $resumeInterruptId, $resumePayload)) {
                    return $this->recoverLocked($runId, $graphs);
                }

                $this->resumeProtocol()->assertMatchingPendingInterrupt($runId, $resumeInterruptId, $interrupt);
                $this->resumeProtocol()->assertInterruptContractResponse($interrupt, $resumePayload, $validateInterruptContract);
            } else {
                if ($interrupt !== null
                    || in_array($run['status'], [RunStatus::INTERRUPTED, RunStatus::DELAYED], true)
                    || is_array(data_get($run, 'meta.runtime.recovery.pending_resume'))) {
                    throw new InvalidArgumentException("Run [{$runId}] requires interrupt_id to resume.");
                }

                if ($resumePayload !== []) {
                    throw new InvalidArgumentException("Run [{$runId}] has no pending interrupt; use recover() without a state patch.");
                }

                if (! $incomingOptions->isDefault()) {
                    $this->updateRun($run, ['meta' => $runtimeOptions->applyToMeta($run['meta'] ?? [])]);
                }

                return $this->recoverLocked($runId, $graphs);
            }

            $this->resumeProtocol()->assertSubgraphResumeBinding($run, $interrupt, $resumePayload, $graphs);

            $state = array_merge($checkpoint['state'], $resumePayload);
            $schedule = $this->resumeProtocol()->resumeSchedule($checkpoint, $interrupt);
            $next = $this->scheduler()->nodeIds($schedule);
            $updates = ['status' => 'running'];
            $meta = is_array($run['meta'] ?? null) ? $run['meta'] : [];

            if (! $incomingOptions->isDefault()) {
                $meta = $runtimeOptions->applyToMeta($meta);
            }

            $updates['meta'] = $this->resumeProtocol()->withPendingResumeRecovery(
                meta: $meta,
                kind: 'resume',
                interruptId: $resumeInterruptId,
                checkpoint: $checkpoint,
                resumePayload: $resumePayload,
                schedule: $schedule,
            );
            $updates['resume_at'] = null;

            $run = $this->transaction(function () use ($run, $resumeInterruptId, $runId, $payload, $updates): array {
                $this->interrupts->resolvePending($resumeInterruptId, $runId, $payload);

                return $this->updateRun($run, $updates);
            });

            $this->dispatchRunEvent('run.resumed', new GraphResumed($runId, $run['thread_id'], $graph->key(), payload: $resumePayload));

            return $this->prepareContinuationLocked($graph, $run, $state, $next, [
                'resume_payload' => $resumePayload,
                'interrupt_id' => $resumeInterruptId,
                'schedule' => $this->scheduler()->serialize($schedule),
                'step' => (int) ($checkpoint['step'] ?? 0),
                'source_checkpoint_id' => $checkpoint['checkpoint_id'] ?? null,
            ], $runtimeOptions);
        });

        return $next instanceof ExecutionFrontier ? $this->drive($graphs[$next->run['graph_key']], $next) : $next;
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    public function resumeWithStateEdit(string $runId, string $interruptId, array $statePatch, array $graphs, ?string $resolvedBy = null): RunResult
    {
        $next = $this->locks->withLock('agent-graph:run:'.$runId, function () use ($runId, $interruptId, $statePatch, $graphs, $resolvedBy): RunResult|ExecutionFrontier {
            $run = $this->runs->find($runId) ?? throw new RuntimeException("Run [{$runId}] was not found.");
            $this->assertRunCanResume($run);
            $graph = $graphs[$run['graph_key']] ?? throw new RuntimeException("Graph [{$run['graph_key']}] is not defined.");
            $this->assertGraphVersionMatches($run, $graph, 'Run');
            $checkpoint = $this->checkpoints->latestForRun($runId) ?? throw new RuntimeException("Run [{$runId}] has no checkpoint.");
            $interrupt = $this->interrupts->pendingForRun($runId);

            if ($interrupt === null && $this->resumeProtocol()->matchesPendingResumeRecovery($run, $interruptId, $statePatch)) {
                return $this->recoverLocked($runId, $graphs);
            }

            $this->resumeProtocol()->assertMatchingPendingInterrupt($runId, $interruptId, $interrupt);

            if (($interrupt['type'] ?? null) !== 'state_edit') {
                throw new InvalidArgumentException("Interrupt [{$interruptId}] is not a state_edit interrupt.");
            }

            $this->resumeProtocol()->assertSubgraphResumeBinding($run, $interrupt, $statePatch, $graphs);
            $this->assertStatePatchMatchesSchema($graph, $statePatch);

            $state = array_merge($checkpoint['state'], $statePatch);
            $schedule = $this->resumeProtocol()->resumeSchedule($checkpoint, $interrupt);
            $next = $this->scheduler()->nodeIds($schedule);
            $response = ['interrupt_id' => $interruptId, 'state' => $statePatch];
            $meta = $this->resumeProtocol()->withPendingResumeRecovery(
                meta: is_array($run['meta'] ?? null) ? $run['meta'] : [],
                kind: 'state_edit',
                interruptId: $interruptId,
                checkpoint: $checkpoint,
                resumePayload: $statePatch,
                schedule: $schedule,
            );
            $run = $this->transaction(function () use ($run, $interruptId, $runId, $response, $resolvedBy, $meta): array {
                $this->interrupts->resolvePending($interruptId, $runId, $response, $resolvedBy);

                return $this->updateRun($run, [
                    'status' => 'running',
                    'resume_at' => null,
                    'meta' => $meta,
                ]);
            });
            $this->dispatchRunEvent('run.resumed', new GraphResumed($runId, $run['thread_id'], $graph->key(), payload: $statePatch));

            return $this->prepareContinuationLocked($graph, $run, $state, $next, [
                'resume_payload' => $statePatch,
                'interrupt_id' => $interruptId,
                'schedule' => $this->scheduler()->serialize($schedule),
                'step' => (int) ($checkpoint['step'] ?? 0),
                'source_checkpoint_id' => $checkpoint['checkpoint_id'] ?? null,
            ]);
        });

        return $next instanceof ExecutionFrontier ? $this->drive($graphs[$next->run['graph_key']], $next) : $next;
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    public function recover(string $runId, array $graphs): RunResult
    {
        $next = $this->locks->withLock(
            'agent-graph:run:'.$runId,
            fn (): RunResult|ExecutionFrontier => $this->recoverLocked($runId, $graphs),
        );

        return $next instanceof ExecutionFrontier ? $this->drive($graphs[$next->run['graph_key']], $next) : $next;
    }

    public function cancel(string $runId, array $meta = []): RunResult
    {
        $run = $this->runs->find($runId) ?? throw new RuntimeException("Run [{$runId}] was not found.");

        if (! RunStatus::isActive($run['status'] ?? null)) {
            throw new RuntimeException("Run [{$runId}] is {$run['status']} and cannot be cancelled.");
        }

        $interrupt = $this->interrupts->pendingForRun($runId);
        $run = $this->transaction(function () use ($run, $runId, $meta, $interrupt): array {
            if (is_array($interrupt) && is_string($interrupt['interrupt_id'] ?? null)) {
                $this->interrupts->resolvePending(
                    $interrupt['interrupt_id'],
                    $runId,
                    [
                        'type' => 'cancelled',
                        'interrupt_id' => $interrupt['interrupt_id'],
                        'meta' => $meta,
                    ],
                    self::class,
                );
            }

            return $this->updateRun($run, [
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'resume_at' => null,
                'meta' => array_merge(
                    $this->resumeProtocol()->withoutPendingResumeRecovery(is_array($run['meta'] ?? null) ? $run['meta'] : []),
                    ['cancelled' => $meta],
                ),
            ]);
        });

        $checkpoint = $this->checkpoints->latestForRun($runId);
        $this->dispatchRunEvent('run.cancelled', new GraphRunCancelled($runId, $run['thread_id'], $run['graph_key'], payload: $meta));

        return new RunResult($run, $checkpoint['state'] ?? []);
    }

    public function checkpoint(string $checkpointId, bool $withWrites = false): ?CheckpointSnapshot
    {
        $checkpoint = $this->checkpoints->find($checkpointId);

        if ($checkpoint === null) {
            return null;
        }

        $previousCheckpointId = $checkpoint['parent_checkpoint_id'] ?? null;
        $previousCheckpoint = is_string($previousCheckpointId) && $previousCheckpointId !== ''
            ? $this->checkpoints->find($previousCheckpointId)
            : null;

        return new CheckpointSnapshot(
            checkpoint: $checkpoint,
            writes: $withWrites ? $this->writes->listForCheckpoint($checkpointId) : [],
            previousCheckpoint: $previousCheckpoint,
        );
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    public function replay(string $checkpointId, array $graphs, ?string $threadId = null, array $meta = []): RunResult
    {
        $checkpoint = $this->checkpoints->find($checkpointId) ?? throw new RuntimeException("Checkpoint [{$checkpointId}] was not found.");
        $graph = $graphs[$checkpoint['graph_key']] ?? throw new RuntimeException("Graph [{$checkpoint['graph_key']}] is not defined.");
        $this->assertGraphVersionMatches($checkpoint, $graph, 'Checkpoint');
        $run = $this->createTimeTravelRun($checkpoint, $threadId, 'replay', $meta);
        $nextNodes = $checkpoint['next_nodes'] ?? [];

        if ($this->isTerminalNext($nextNodes)) {
            return $this->completeTimeTravelRun($run, $checkpoint, 'replay');
        }

        return $this->drive($graph, $this->prepareContinuation($graph, $run, $checkpoint['state'], $nextNodes, [
            'schedule' => $this->scheduler()->fromCheckpoint($checkpoint),
            'source_checkpoint_id' => $checkpoint['checkpoint_id'],
            'step' => (int) $checkpoint['step'],
        ]));
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    public function fork(string $checkpointId, array $statePatch, array $graphs, ?string $threadId = null, ?string $asNode = null, array $meta = []): RunResult
    {
        $checkpoint = $this->checkpoints->find($checkpointId) ?? throw new RuntimeException("Checkpoint [{$checkpointId}] was not found.");
        $graph = $graphs[$checkpoint['graph_key']] ?? throw new RuntimeException("Graph [{$checkpoint['graph_key']}] is not defined.");
        $this->assertGraphVersionMatches($checkpoint, $graph, 'Checkpoint');

        $this->assertStatePatchMatchesSchema($graph, $statePatch);

        if ($asNode !== null && ! $graph->hasEndpoint($asNode)) {
            throw new InvalidArgumentException("Unknown endpoint [{$asNode}] for fork.");
        }

        $state = (new StateReducer($this->inferReducers($graph)))->apply($checkpoint['state'], $statePatch);
        $nextSchedule = $asNode === null
            ? $this->scheduler()->fromCheckpoint($checkpoint)
            : $this->scheduler()->normalize($graph->successorsOf($asNode, $state));
        $nextNodes = $this->scheduler()->nodeIds($nextSchedule);
        $run = $this->createTimeTravelRun($checkpoint, $threadId, 'fork', $meta);
        $forkCheckpoint = $this->createSyntheticCheckpoint($run, $checkpoint, $state, $nextNodes, ['source' => 'fork'], $nextSchedule);

        if ($this->isTerminalNext($nextNodes)) {
            $run = $this->updateRun($run, [
                'status' => 'completed',
                'current_checkpoint_id' => $forkCheckpoint['checkpoint_id'],
            ]);

            return new RunResult($run, $state);
        }

        return $this->drive($graph, $this->prepareContinuation($graph, $run, $state, $nextNodes, [
            'schedule' => $nextSchedule,
            'source_checkpoint_id' => $forkCheckpoint['checkpoint_id'],
            'step' => (int) $checkpoint['step'],
        ]));
    }

    public function inspect(string $runId, bool $withHistory = false, bool $withTraces = false): ?RunSnapshot
    {
        $run = $this->runs->find($runId);

        if ($run === null) {
            return null;
        }

        $checkpoint = $this->checkpoints->latestForRun($runId);

        return new RunSnapshot(
            run: $run,
            checkpoint: $checkpoint,
            checkpoints: $withHistory ? $this->checkpoints->listForRun($runId) : [],
            writes: $this->writes->listForRun($runId),
            interrupt: $this->interrupts->pendingForRun($runId),
            traces: $withTraces ? $this->traces->listForRun($runId) : [],
        );
    }

    public function timeline(string $runId, bool $includeState = false, bool $includeDiff = true): ?RunTimeline
    {
        return $this->inspector()->timeline($runId, $includeState, $includeDiff);
    }

    public function runs(array $filters = [], int $limit = 50): array
    {
        return $this->runs->list($filters, $limit);
    }

    public function childRuns(string $parentRunId, int $limit = 50): array
    {
        return $this->runs->listChildRuns($parentRunId, $limit);
    }

    public function tasks(array $filters = [], int $limit = 50): array
    {
        return $this->tasks->list($filters, $limit);
    }

    public function nodeExecutions(string $runId): array
    {
        return $this->nodeExecutionStore()->listForRun($runId);
    }

    public function latestForThreadGraph(string $threadId, string $graphKey, array $statuses = RunStatus::ACTIVE): ?array
    {
        return $this->runs->latestForThreadGraph($threadId, $graphKey, $statuses);
    }

    public function expireInterrupts(mixed $now = null): int
    {
        return $this->interrupts->expirePending($now ?? now());
    }

    public function timeTravelChildren(string $checkpointId, int $limit = 50): array
    {
        return $this->runs->listTimeTravelChildren($checkpointId, $limit);
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    public function executeQueuedNode(string $executionId, array $graphs): ?array
    {
        return $this->executeNodeReceipt($executionId, $graphs, true);
    }

    protected function executeNodeReceipt(string $executionId, array $graphs, bool $deliverContinuation): ?array
    {
        $store = $this->nodeExecutionStore();
        $existing = $store->find($executionId);

        if ($existing === null) {
            return null;
        }

        $existingRun = $this->runs->find((string) $existing['run_id']);

        if ($existingRun === null || ($existingRun['status'] ?? null) !== 'running') {
            return $existing;
        }

        $peers = $store->listForRunStep((string) $existing['run_id'], (int) $existing['step']);

        if (array_filter($peers, fn (array $peer): bool => ($peer['status'] ?? null) === 'failed') !== []) {
            if ($deliverContinuation) {
                $this->continueQueuedSuperstep((string) $existing['run_id'], (int) $existing['step'], $graphs);
            }

            return $store->find($executionId);
        }

        if (in_array($existing['status'], ['pending', 'running'], true) && is_string($existing['checkpoint_id'] ?? null)) {
            $source = $this->checkpoints->find($existing['checkpoint_id']);
            $this->resumeProtocol()->assertCheckpointContinuationIsSafe($existingRun, $source, $peers);
        }

        $execution = $store->claim($executionId, now()->addSeconds((int) config('agent-graph.execution.node_lease_seconds', 300)));

        if ($execution === null) {
            return null;
        }

        if (in_array($execution['status'], ['completed', 'interrupted', 'failed'], true)) {
            if ($deliverContinuation) {
                $this->dispatchContinueSuperstep((string) $execution['run_id'], (int) $execution['step']);
            }

            return $execution;
        }

        $run = $this->runs->find((string) $execution['run_id']);

        if ($run === null || RunStatus::isTerminal($run['status'] ?? null)) {
            return $execution;
        }

        $graph = $graphs[$run['graph_key']] ?? throw new RuntimeException("Graph [{$run['graph_key']}] is not defined.");
        $this->assertGraphVersionMatches($run, $graph, 'Run');

        $nodeId = (string) $execution['node_id'];
        $nodeState = is_array($execution['node_state'] ?? null) ? $execution['node_state'] : [];
        $claimToken = $execution['claim_token'] ?? null;

        if (! is_string($claimToken) || $claimToken === '') {
            throw new RuntimeException("Node execution [{$executionId}] is missing its active claim token.");
        }

        $this->dispatchRunEvent('node.started', new GraphNodeStarted($run['public_id'], $run['thread_id'], $graph->key(), $nodeId));

        $authority = new ExecutionAuthority($this->runs, $run, $store, $executionId, $claimToken);
        $payload = [];

        try {
            $result = $this->nodeExecutor()->execute(
                $graph,
                $nodeId,
                $nodeState,
                $run,
                $authority,
                $execution['checkpoint_id'] ?? null,
                is_array($execution['resume_payload'] ?? null) ? $execution['resume_payload'] : null,
                is_string($execution['interrupt_id'] ?? null) ? $execution['interrupt_id'] : null,
            );

            $authority->assertCurrent();

            if ($result->status() !== 'failed') {
                $this->assertNodeResultTargetsAreKnown($graph, $nodeId, $result);
                $this->assertStatePatchMatchesSchema($graph, $result->writes());
                $branchState = (new StateReducer($this->inferReducers($graph)))->apply($nodeState, $result->writes());
                $nextSchedule = $this->nextScheduleFor($graph, $nodeId, $result, $branchState);
                $payload = [
                    'writes' => $result->writes(),
                    'next_schedule' => $this->scheduler()->serialize($nextSchedule),
                    'interrupt' => $result->status() === 'interrupted'
                        ? [
                            'type' => $result->interruptType(),
                            'payload' => $result->interruptPayload(),
                            'expires_at' => $result->interruptPolicy()?->expiresAtValue(),
                            'schedule' => data_get($execution, 'meta.schedule', $nodeId),
                        ]
                        : null,
                    'meta' => $result->meta(),
                ];
            }
        } catch (RunStateChangedException|NodeExecutionClaimLostException) {
            return $store->find($executionId);
        } catch (Throwable $exception) {
            try {
                $authority->assertCurrent();
            } catch (RunStateChangedException|NodeExecutionClaimLostException) {
                return $store->find($executionId);
            }

            return $this->failQueuedExecution($execution, $claimToken, $run, $graph, RuntimeError::fromThrowable($exception));
        }

        if ($result->status() === 'failed') {
            return $this->failQueuedExecution(
                $execution, $claimToken, $run, $graph,
                RuntimeError::fromMessage((string) $result->failureMessage(), meta: $result->meta()),
            );
        }

        try {
            $updated = $result->status() === 'interrupted'
                ? $store->interrupt($executionId, $claimToken, $payload)
                : $store->complete($executionId, $claimToken, $payload);
        } catch (NodeExecutionClaimLostException) {
            return $store->find($executionId);
        }

        // Delivery may be retried without changing the durable node outcome.
        if ($deliverContinuation) {
            $this->dispatchContinueSuperstep($run['public_id'], (int) $execution['step']);
        }

        return $updated;
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    public function continueQueuedSuperstep(string $runId, int $step, array $graphs): ?RunResult
    {
        $next = $this->commitSuperstep($runId, $step, $graphs);

        return $next instanceof ExecutionFrontier ? $this->drive($graphs[$next->run['graph_key']], $next) : $next;
    }

    protected function commitSuperstep(string $runId, int $step, array $graphs): RunResult|ExecutionFrontier|null
    {
        try {
            return $this->locks->withLock('agent-graph:run:'.$runId,
                fn (): RunResult|ExecutionFrontier|null => $this->commitSuperstepOwned($runId, $step, $graphs));
        } catch (RunStateChangedException) {
            return $this->currentResult($runId);
        }
    }

    protected function commitSuperstepOwned(string $runId, int $step, array $graphs): RunResult|ExecutionFrontier|null
    {
        $run = $this->runs->find($runId);

        if ($run === null) {
            return null;
        }

        $options = RuntimeOptions::fromRun($run);
        $latestCheckpoint = $this->checkpoints->latestForRun($runId);

        if (RunStatus::isTerminal($run['status'] ?? null)) {
            return new RunResult($run, $latestCheckpoint['state'] ?? $run['input'] ?? []);
        }

        if ($latestCheckpoint !== null && (int) $latestCheckpoint['step'] >= $step) {
            return new RunResult($run, $latestCheckpoint['state'], $this->interrupts->pendingForRun($runId));
        }

        $executions = $this->nodeExecutionStore()->listForRunStep($runId, $step);

        if ($executions === []) {
            return new RunResult($run, $latestCheckpoint['state'] ?? $run['input'] ?? []);
        }

        $graph = $graphs[$run['graph_key']] ?? throw new RuntimeException("Graph [{$run['graph_key']}] is not defined.");
        $this->assertGraphVersionMatches($run, $graph, 'Run');

        $failed = collect($executions)->first(fn (array $execution): bool => $execution['status'] === 'failed');

        if ($failed !== null) {
            $error = is_array($failed['error'] ?? null) ? $failed['error'] : RuntimeError::fromMessage('Queued node execution failed.');
            $this->failQueuedRunLocked($run, $graph, (string) $failed['node_id'], $error);

            return new RunResult($this->runs->find($runId) ?? $run, $failed['base_state'] ?? []);
        }

        if (array_filter($executions, fn (array $execution): bool => in_array($execution['status'], ['pending', 'running'], true)) !== []) {
            return new RunResult($run, $executions[0]['base_state'] ?? []);
        }

        $this->resumeProtocol()->assertCheckpointContinuationIsSafe($run, $latestCheckpoint, $executions);

        $baseState = is_array($executions[0]['base_state'] ?? null) ? $executions[0]['base_state'] : [];
        $results = array_map(fn (array $execution): array => [
            'node_id' => (string) $execution['node_id'],
            'result' => $this->nodeResultFromExecution($execution),
        ], $executions);
        $interrupted = collect($executions)->first(fn (array $execution): bool => $execution['status'] === 'interrupted');

        if ($interrupted !== null && count($executions) > 1) {
            return $this->failRun(
                $run,
                $graph,
                (string) $interrupted['node_id'],
                $baseState,
                new RuntimeException('Parallel interrupts are not supported in the same superstep. Route human review after fan-in.'),
            );
        }

        try {
            $state = $this->applySuperstepWrites($baseState, $results, $this->inferReducers($graph));
        } catch (Throwable $exception) {
            return $this->failRun($run, $graph, 'superstep', $baseState, $exception);
        }

        $nextSchedule = $this->scheduler()->normalize(array_merge(...array_map(
            fn (array $execution): array => is_array($execution['next_schedule'] ?? null) ? $execution['next_schedule'] : [],
            $executions,
        )));
        try {
            [$checkpoint, $run, $interrupt, $resumeAt] = $this->persistSuperstepCheckpoint(
                $graph, $run, $state, $results, $nextSchedule, $step,
                $executions[0]['checkpoint_id'] ?? null,
                $interrupted !== null ? array_merge($interrupted['interrupt'], ['node_id' => $interrupted['node_id']]) : null,
            );
        } catch (Throwable $exception) {
            return $this->failRun($run, $graph, 'superstep', $baseState, $exception);
        }

        $this->notifySuperstepCommitted($graph, $run, $results, $checkpoint, $interrupt, $resumeAt);
        $this->assertRunRevision($run);

        if ($interrupt !== null) {
            return new RunResult($run, $state, $interrupt);
        }

        if ($nextSchedule === []) {
            $run = $this->transaction(fn () => $this->updateRun($run, [
                'status' => 'completed',
                'current_checkpoint_id' => $checkpoint['checkpoint_id'],
            ]));
            $this->dispatchRunEvent('run.completed', new GraphRunCompleted($run['public_id'], $run['thread_id'], $graph->key(), payload: ['state' => $state]));

            return new RunResult($run, $state);
        }

        return $this->scheduleSuperstep($graph, $run, $state, $nextSchedule, $step, $checkpoint['checkpoint_id'], options: $options);
    }

    protected function prepareContinuation(GraphDefinition $graph, array $run, array $state, array $nextNodes, array $resumeContext = [], ?RuntimeOptions $options = null): RunResult|ExecutionFrontier
    {
        return $this->locks->withLock('agent-graph:run:'.$run['public_id'], function () use ($graph, $run, $state, $nextNodes, $resumeContext, $options): RunResult|ExecutionFrontier {
            return $this->prepareContinuationLocked($graph, $run, $state, $nextNodes, $resumeContext, $options);
        });
    }

    protected function prepareContinuationLocked(GraphDefinition $graph, array $run, array $state, array $nextNodes, array $resumeContext = [], ?RuntimeOptions $options = null): RunResult|ExecutionFrontier
    {
        try {
            return $this->scheduleContinuation($graph, $run, $state, $nextNodes, $resumeContext, $options);
        } catch (RunStateChangedException) {
            return $this->currentResult($run['public_id']);
        }
    }

    protected function scheduleContinuation(GraphDefinition $graph, array $run, array $state, array $nextNodes, array $resumeContext, ?RuntimeOptions $options): RunResult|ExecutionFrontier
    {
        $options ??= RuntimeOptions::fromRun($run);
        $freshRun = $this->runs->find($run['public_id']) ?? $run;

        if (RunStatus::isTerminal($freshRun['status'] ?? null)) {
            $checkpoint = $this->checkpoints->latestForRun($freshRun['public_id']);

            return new RunResult($freshRun, $checkpoint['state'] ?? $state);
        }

        $this->assertRunRevision($run);
        $latestCheckpoint = $this->checkpoints->latestForRun($run['public_id']);
        $step = (int) ($resumeContext['step'] ?? ($latestCheckpoint['step'] ?? 0));
        $checkpointId = $resumeContext['source_checkpoint_id'] ?? ($latestCheckpoint['checkpoint_id'] ?? null);
        $schedule = is_array($resumeContext['schedule'] ?? null)
            ? $this->scheduler()->normalize($resumeContext['schedule'])
            : $this->scheduler()->normalize($nextNodes);
        $resumePayload = array_key_exists('resume_payload', $resumeContext) && is_array($resumeContext['resume_payload'])
            ? $resumeContext['resume_payload']
            : null;
        $resumeInterruptId = is_string($resumeContext['interrupt_id'] ?? null)
            ? $resumeContext['interrupt_id']
            : null;
        $applyResumeContext = array_key_exists('resume_payload', $resumeContext) || $resumeInterruptId !== null;

        return $this->scheduleSuperstep(
            $graph, $run, $state, $schedule, $step, $checkpointId,
            $applyResumeContext ? $resumePayload : null,
            $applyResumeContext ? $resumeInterruptId : null, $options,
        );
    }

    /**
     * @param  array<int, Send>  $schedule
     */
    protected function scheduleSuperstep(GraphDefinition $graph, array $run, array $state, array $schedule, int $currentStep, ?string $checkpointId, ?array $resumePayload = null, ?string $interruptId = null, ?RuntimeOptions $options = null): RunResult|ExecutionFrontier
    {
        $options ??= RuntimeOptions::fromRun($run);

        if ($schedule === []) {
            $run = $this->transaction(fn () => $this->updateRun($run, [
                'status' => 'completed',
                'current_checkpoint_id' => $checkpointId,
            ]));
            $this->dispatchRunEvent('run.completed', new GraphRunCompleted($run['public_id'], $run['thread_id'], $graph->key(), payload: ['state' => $state]));

            return new RunResult($run, $state);
        }

        if ($currentStep >= $options->maxSteps()) {
            $run = $this->updateRun($run, [
                'status' => 'failed',
                'error' => RuntimeError::fromMessage('Maximum graph steps exceeded.', code: 'max_steps_exceeded'),
            ]);
            $this->dispatchRunEvent('run.failed', new GraphRunFailed($run['public_id'], $run['thread_id'], $graph->key(), payload: $run['error']));

            return new RunResult($run, $state);
        }

        try {
            $this->scheduler()->assertWithinLimit($schedule);
            if (count($schedule) > 1 && array_filter($schedule, fn (Send $send): bool => $graph->nodeCanInterrupt($send->node())) !== []) {
                throw new RuntimeException('Parallel interrupts are not supported in the same superstep. Route human review after fan-in.');
            }
        } catch (Throwable $exception) {
            return $this->failRun($run, $graph, 'superstep', $state, $exception);
        }

        $step = $currentStep + 1;
        $store = $this->nodeExecutionStore();
        $existing = $store->listForRunStep($run['public_id'], $step);

        if ($existing !== []) {
            $this->assertRunRevision($run);

            return new ExecutionFrontier($run, $step, $existing);
        }

        return $this->transaction(function () use ($store, $run, $checkpointId, $step, $schedule, $state, $resumePayload, $interruptId): ExecutionFrontier {
            $run = $this->updateRun($run, ['meta' => $this->resumeProtocol()->withoutPendingResumeRecovery($run['meta'] ?? [])]);
            $executions = [];

            foreach ($schedule as $scheduleIndex => $scheduledNode) {
                $executions[] = $store->schedule([
                    'run_id' => $run['public_id'],
                    'checkpoint_id' => $checkpointId,
                    'step' => $step,
                    'schedule_index' => $scheduleIndex,
                    'node_id' => $scheduledNode->node(),
                    'status' => 'pending',
                    'base_state' => $state,
                    'node_state' => array_merge($state, $scheduledNode->input()),
                    'resume_payload' => $resumePayload,
                    'interrupt_id' => $interruptId,
                    'writes' => [],
                    'next_schedule' => [],
                    'interrupt' => null,
                    'error' => null,
                    'meta' => [
                        'schedule' => $scheduledNode->toArray(),
                    ],
                ]);
            }

            return new ExecutionFrontier($run, $step, $executions);
        });
    }

    /** Deliver committed receipts outside coordination locks; only scheduling and commits take the run lock. */
    protected function drive(GraphDefinition $graph, RunResult|ExecutionFrontier $next): RunResult
    {
        if ($next instanceof RunResult) {
            return $next;
        }

        $runId = $next->run['public_id'];
        try {
            while ($next instanceof ExecutionFrontier) {
                $this->assertRunRevision($next->run);
                if ($this->queuesSupersteps()) {
                    $this->redispatchQueuedFrontier($runId, $next->step, $next->executions);

                    return $this->currentResult($runId);
                }
                foreach ($next->executions as $execution) {
                    $this->assertRunRevision($next->run);
                    $this->executeNodeReceipt($execution['execution_id'], [$graph->key() => $graph], false);
                }
                $this->assertRunRevision($next->run);
                $next = $this->commitSuperstep($runId, $next->step, [$graph->key() => $graph])
                    ?? $this->currentResult($runId);
            }

            return $next;
        } catch (RunStateChangedException) {
            return $this->currentResult($runId);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $executions
     */
    protected function redispatchQueuedFrontier(string $runId, int $step, array $executions): void
    {
        if (array_filter($executions, fn (array $execution): bool => ($execution['status'] ?? null) === 'failed') !== []) {
            $this->dispatchContinueSuperstep($runId, $step);

            return;
        }

        $active = array_filter(
            $executions,
            fn (array $execution): bool => in_array($execution['status'] ?? null, ['pending', 'running'], true),
        );

        if ($active === []) {
            $this->dispatchContinueSuperstep($runId, $step);

            return;
        }

        foreach ($active as $execution) {
            $executionId = $execution['execution_id'] ?? null;

            if (is_string($executionId) && $executionId !== '') {
                $this->dispatchNodeExecution($executionId);
            }
        }
    }

    protected function nodeResultFromExecution(array $execution): NodeResult
    {
        $writes = is_array($execution['writes'] ?? null) ? $execution['writes'] : [];
        $meta = is_array($execution['meta'] ?? null) ? $execution['meta'] : [];

        if (($execution['status'] ?? null) === 'interrupted') {
            $interrupt = is_array($execution['interrupt'] ?? null) ? $execution['interrupt'] : [];

            return NodeResult::interrupt(
                (string) ($interrupt['type'] ?? 'input'),
                is_array($interrupt['payload'] ?? null) ? $interrupt['payload'] : [],
                $writes,
            )->withMeta($meta);
        }

        return NodeResult::write($writes)->withMeta($meta);
    }

    /**
     * @param  array<int, array{node_id: string, result: NodeResult}>  $results
     * @param  array<int, Send>  $nextSchedule
     * @return array{array, array, ?array, ?CarbonImmutable}
     */
    protected function persistSuperstepCheckpoint(GraphDefinition $graph, array $run, array $state, array $results, array $nextSchedule, int $step, ?string $parentCheckpointId, ?array $wait = null): array
    {
        return $this->transaction(function () use ($graph, $run, $state, $results, $nextSchedule, $step, $parentCheckpointId, $wait): array {
            $run = $this->updateRun($run, ['meta' => $this->resumeProtocol()->withoutPendingResumeRecovery($run['meta'] ?? [])]);
            $storedSchedule = $wait !== null
                ? $this->scheduler()->normalize([$wait['schedule'] ?? (string) $wait['node_id']])
                : $nextSchedule;
            $meta = $this->checkpointMetaForResults($results, $storedSchedule);

            if ($wait !== null) {
                data_set($meta, 'runtime.wait', ['node_id' => $wait['node_id'], 'type' => $wait['type']]);
            }

            $checkpoint = $this->checkpoints->create([
                'run_id' => $run['public_id'],
                'thread_id' => $run['thread_id'],
                'graph_key' => $graph->key(),
                'graph_version' => $graph->version(),
                'parent_checkpoint_id' => $parentCheckpointId,
                'step' => $step,
                'state' => $state,
                'next_nodes' => $this->scheduler()->nodeIds($storedSchedule),
                'completed_nodes' => array_column($results, 'node_id'),
                'interrupts' => [],
                'meta' => $meta,
            ]);

            foreach ($results as $record) {
                $result = $record['result'];
                $this->writes->createMany($run['public_id'], $checkpoint['checkpoint_id'], $record['node_id'], $result->writes(), $result->meta());
            }

            $run = $this->updateRun($run, ['current_checkpoint_id' => $checkpoint['checkpoint_id']]);
            $interrupt = null;
            $resumeAt = null;

            if ($wait !== null) {
                $payload = $wait['payload'];
                $type = (string) $wait['type'];
                $status = $type === 'delay' ? 'delayed' : 'interrupted';

                if ($status === 'delayed') {
                    $resumeAt = $this->normaliseResumeAt($payload['resume_at'] ?? null);
                    $payload['resume_at'] = $resumeAt->toJSON();
                }

                $interrupt = $this->interrupts->create([
                    'run_id' => $run['public_id'],
                    'checkpoint_id' => $checkpoint['checkpoint_id'],
                    'node_id' => $wait['node_id'],
                    'type' => $type,
                    'payload' => $payload,
                    'expires_at' => isset($wait['expires_at']) ? CarbonImmutable::parse($wait['expires_at']) : null,
                ]);

                $run = $this->updateRun($run, [
                    'status' => $status,
                    'current_checkpoint_id' => $checkpoint['checkpoint_id'],
                    'resume_at' => $resumeAt,
                ]);
            }

            return [$checkpoint, $run, $interrupt, $resumeAt];
        });
    }

    /**
     * @param  array<int, array{node_id: string, result: NodeResult}>  $results
     */
    protected function notifySuperstepCommitted(GraphDefinition $graph, array $run, array $results, array $checkpoint, ?array $interrupt, ?DateTimeInterface $resumeAt): void
    {
        $this->recordTrace($run['public_id'], 'checkpoint.created', [
            'checkpoint_id' => $checkpoint['checkpoint_id'], 'nodes' => array_column($results, 'node_id'),
        ]);

        if ($interrupt !== null && $resumeAt !== null) {
            $this->delayScheduler()->schedule($run['public_id'], [
                'interrupt_id' => $interrupt['interrupt_id'],
            ], $resumeAt);
        }

        foreach ($results as $record) {
            $result = $record['result'];
            $this->dispatchRunEvent('node.completed', new GraphNodeCompleted($run['public_id'], $run['thread_id'], $graph->key(), $record['node_id'], ['writes' => $result->writes()]));
            $this->dispatchRunEvent('checkpoint.created', new GraphCheckpointCreated($run['public_id'], $run['thread_id'], $graph->key(), $record['node_id'], ['checkpoint_id' => $checkpoint['checkpoint_id']]));
        }

        if ($interrupt !== null) {
            $this->dispatchRunEvent('interrupt.created', new GraphInterrupted($run['public_id'], $run['thread_id'], $graph->key(), $interrupt['node_id'], $interrupt));
        }
    }

    protected function dispatchNodeExecution(string $executionId): void
    {
        AgentGraphQueue::configure(NodeExecutionJob::dispatch($executionId));
    }

    protected function dispatchContinueSuperstep(string $runId, int $step): void
    {
        AgentGraphQueue::configure(ContinueSuperstepJob::dispatch($runId, $step));
    }

    protected function failQueuedExecution(array $execution, string $claimToken, array $run, GraphDefinition $graph, array $error): ?array
    {
        $store = $this->nodeExecutionStore();

        try {
            $failed = $store->fail($execution['execution_id'], $claimToken, $error);
        } catch (NodeExecutionClaimLostException) {
            return $store->find($execution['execution_id']);
        }

        try {
            $this->failQueuedRunLocked($run, $graph, $execution['node_id'], $error);
        } catch (RunStateChangedException) {
            // A concurrent control transition owns the run now.
        }

        return $failed;
    }

    protected function failQueuedRunLocked(array $run, GraphDefinition $graph, string $nodeId, array $error): void
    {
        $run = $this->runs->find($run['public_id']);

        if ($run === null || RunStatus::isTerminal($run['status'] ?? null)) {
            return;
        }

        $run = $this->transaction(fn () => $this->updateRun($run, ['status' => 'failed', 'error' => $error]));
        $this->recordTrace($run['public_id'], 'node.failed', array_merge(['node' => $nodeId], $error));
        $this->dispatchRunEvent('node.failed', new GraphNodeFailed($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, $error));
        $this->dispatchRunEvent('run.failed', new GraphRunFailed($run['public_id'], $run['thread_id'], $graph->key(), payload: $run['error']));
    }

    protected function queuesSupersteps(): bool
    {
        return config('agent-graph.execution.mode', 'sync') === 'queued_supersteps';
    }

    protected function failRun(array $run, GraphDefinition $graph, string $nodeId, array $state, Throwable $exception): RunResult
    {
        if ($exception instanceof RunStateChangedException) {
            return $this->currentResult($run['public_id']);
        }
        $this->assertRunRevision($run);

        $error = RuntimeError::fromThrowable($exception);
        $run = $this->transaction(fn () => $this->updateRun($run, [
            'status' => 'failed',
            'error' => $error,
        ]));

        $this->recordTrace($run['public_id'], 'node.failed', array_merge(['node' => $nodeId], $error));
        $this->dispatchRunEvent('node.failed', new GraphNodeFailed($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, $error));
        $this->dispatchRunEvent('run.failed', new GraphRunFailed($run['public_id'], $run['thread_id'], $graph->key(), payload: $run['error']));

        return new RunResult($run, $state);
    }

    protected function normaliseResumeAt(mixed $resumeAt): CarbonImmutable
    {
        if ($resumeAt === null || $resumeAt === '') {
            throw new RuntimeException('Delay interrupts require a resume_at timestamp.');
        }

        try {
            return CarbonImmutable::parse($resumeAt);
        } catch (Throwable $exception) {
            throw new RuntimeException('Delay interrupt resume_at must be a valid date/time.', previous: $exception);
        }
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    protected function recoverLocked(string $runId, array $graphs): RunResult|ExecutionFrontier
    {
        $run = $this->runs->find($runId) ?? throw new RuntimeException("Run [{$runId}] was not found.");
        $checkpoint = $this->checkpoints->latestForRun($runId);
        $interrupt = $this->interrupts->pendingForRun($runId);
        $state = is_array($checkpoint['state'] ?? null)
            ? $checkpoint['state']
            : (is_array($run['input'] ?? null) ? $run['input'] : []);

        if (($run['status'] ?? null) === 'delayed') {
            $this->redeliverPendingDelay($run, $checkpoint, $interrupt, $graphs);
        }

        if (($run['status'] ?? null) !== 'running' || $interrupt !== null) {
            return new RunResult($run, $state, $interrupt);
        }

        $graph = $graphs[$run['graph_key']] ?? throw new RuntimeException("Graph [{$run['graph_key']}] is not defined.");
        $this->assertGraphVersionMatches($run, $graph, 'Run');

        // A committed queue frontier is recovery authority even before step one
        // creates a checkpoint, or after an accepted resume was scheduled.
        $queuedStep = (int) ($checkpoint['step'] ?? 0) + 1;
        $executionStore = $this->nodeExecutionStore();
        $executions = $executionStore->listForRunStep($runId, $queuedStep);
        $pending = data_get($run, 'meta.runtime.recovery.pending_resume');

        $this->resumeProtocol()->assertCheckpointContinuationIsSafe($run, $checkpoint, $executions);

        if ($executions !== []) {
            return new ExecutionFrontier($run, $queuedStep, $executions);
        }

        if ($checkpoint === null) {
            throw new RuntimeException("Run [{$runId}] has no checkpoint to recover from.");
        }

        $resumeContext = [];

        if (is_array($pending)) {
            $sourceCheckpointId = (string) ($pending['source_checkpoint_id'] ?? '');
            $source = $sourceCheckpointId !== '' ? $this->checkpoints->find($sourceCheckpointId) : null;

            if ($source === null || ($source['run_id'] ?? null) !== $runId) {
                throw new RuntimeException("Run [{$runId}] has an invalid pending resume recovery checkpoint.");
            }

            $resumePayload = is_array($pending['resume_payload'] ?? null) ? $pending['resume_payload'] : [];
            $schedule = is_array($pending['schedule'] ?? null)
                ? $this->scheduler()->normalize($pending['schedule'])
                : $this->scheduler()->fromCheckpoint($source);
            $state = array_merge(is_array($source['state'] ?? null) ? $source['state'] : [], $resumePayload);
            $resumeContext = [
                'resume_payload' => $resumePayload,
                'interrupt_id' => is_string($pending['interrupt_id'] ?? null) ? $pending['interrupt_id'] : null,
                'schedule' => $this->scheduler()->serialize($schedule),
                'step' => (int) ($pending['step'] ?? $source['step'] ?? 0),
                'source_checkpoint_id' => $source['checkpoint_id'],
            ];
        } else {
            $schedule = $this->scheduler()->fromCheckpoint($checkpoint);
            $resumeContext = [
                'schedule' => $this->scheduler()->serialize($schedule),
                'step' => (int) ($checkpoint['step'] ?? 0),
                'source_checkpoint_id' => $checkpoint['checkpoint_id'],
            ];
        }

        return $this->prepareContinuationLocked(
            graph: $graph,
            run: $run,
            state: $state,
            nextNodes: $this->scheduler()->nodeIds($schedule),
            resumeContext: $resumeContext,
            options: RuntimeOptions::fromRun($run),
        );
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    protected function redeliverPendingDelay(array $run, ?array $checkpoint, ?array $interrupt, array $graphs): void
    {
        $runId = $run['public_id'];
        $graph = $graphs[$run['graph_key']] ?? throw new RuntimeException("Graph [{$run['graph_key']}] is not defined.");
        $this->assertGraphVersionMatches($run, $graph, 'Run');
        $interruptId = $interrupt['interrupt_id'] ?? null;
        $nodeId = $interrupt['node_id'] ?? null;
        $checkpointId = $checkpoint['checkpoint_id'] ?? null;

        if ($checkpoint === null || $interrupt === null
            || ! is_string($interruptId) || $interruptId === ''
            || ! is_string($nodeId) || $nodeId === ''
            || ! is_string($checkpointId) || $checkpointId === ''
            || ($interrupt['status'] ?? null) !== 'pending'
            || ($interrupt['type'] ?? null) !== 'delay'
            || ($interrupt['run_id'] ?? null) !== $runId
            || ($checkpoint['run_id'] ?? null) !== $runId
            || ($checkpoint['thread_id'] ?? null) !== $run['thread_id']
            || ($checkpoint['graph_key'] ?? null) !== $run['graph_key']
            || ($checkpoint['checkpoint_id'] ?? null) !== ($run['current_checkpoint_id'] ?? null)
            || ($checkpoint['checkpoint_id'] ?? null) !== ($interrupt['checkpoint_id'] ?? null)
            || ($checkpoint['next_nodes'] ?? null) !== [$nodeId]
            || data_get($checkpoint, 'meta.runtime.wait.type') !== 'delay'
            || data_get($checkpoint, 'meta.runtime.wait.node_id') !== $nodeId) {
            throw new RuntimeException("Run [{$runId}] has no matching durable delay authority; legacy or inconsistent waits require reconciliation.");
        }

        $this->assertGraphVersionMatches($checkpoint, $graph, 'Checkpoint');
        $storedResumeAt = data_get($interrupt, 'payload.resume_at');
        $resumeAt = $this->normaliseResumeAt($storedResumeAt);

        // The committed interrupt contains the normalized absolute timestamp.
        // Never re-interpret relative input or restart the original delay.
        if ($resumeAt->toJSON() !== $storedResumeAt) {
            throw new RuntimeException("Run [{$runId}] has an invalid persisted delay timestamp; reconciliation is required.");
        }

        // Delivery is repeatable; the existing interrupt remains the authority.
        // The bound scheduler still owns transport, due-time handling and retries.
        $this->delayScheduler()->schedule($runId, ['interrupt_id' => $interruptId], $resumeAt);
    }

    protected function updateRun(array $run, array $attributes): array
    {
        return $this->runs->transition($run['public_id'], (int) $run['revision'], $attributes);
    }

    protected function assertRunRevision(array $run): void
    {
        $current = $this->runs->find($run['public_id']);
        if ($current === null || (int) $current['revision'] !== (int) $run['revision']) {
            throw new RunStateChangedException("Run [{$run['public_id']}] changed during execution.");
        }
    }

    protected function currentResult(string $runId): RunResult
    {
        return ($this->inspect($runId) ?? throw new RuntimeException("Run [{$runId}] was not found."))->toRunResult();
    }

    protected function recordTrace(string $runId, string $event, array $payload = []): void
    {
        $this->container->make(RunEventDispatcher::class)->notify(fn () => $this->traces->record($runId, $event, $payload));
    }

    protected function transaction(callable $callback): mixed
    {
        $snapshots = [];
        foreach ([$this->runs, $this->checkpoints, $this->writes, $this->interrupts, $this->nodeExecutionStore()] as $store) {
            if ($store instanceof InMemoryStore) {
                $snapshots[] = [$store, $store->snapshot()];
            }
        }
        try {
            return $this->container->make('db')->connection(AgentGraphDatabase::connectionName())->transaction($callback);
        } catch (Throwable $exception) {
            foreach ($snapshots as [$store, $snapshot]) {
                $store->restore($snapshot);
            }
            throw $exception;
        }
    }

    protected function nextNodesFor(GraphDefinition $graph, string $nodeId, NodeResult $result, array $state): array
    {
        if ($result->nextNode() !== null) {
            return [$result->nextNode()];
        }

        if ($result->status() === 'completed') {
            return [StateGraph::END];
        }

        return $graph->resolveNext($nodeId, $state);
    }

    protected function assertNodeResultTargetsAreKnown(GraphDefinition $graph, string $sourceNode, NodeResult $result): void
    {
        if ($result->nextNode() !== null && ! $graph->hasEndpoint($result->nextNode())) {
            throw new InvalidArgumentException("Node [{$sourceNode}] returned unknown goto target [{$result->nextNode()}].");
        }

        foreach ($result->sends() as $send) {
            if (! $graph->hasEndpoint($send->node()) || in_array($send->node(), [StateGraph::START, StateGraph::END], true)) {
                throw new InvalidArgumentException("Node [{$sourceNode}] returned unknown send target [{$send->node()}].");
            }
        }
    }

    /**
     * @return array<int, Send>
     */
    protected function nextScheduleFor(GraphDefinition $graph, string $nodeId, NodeResult $result, array $state): array
    {
        if ($result->status() === 'completed') {
            return [];
        }

        if ($result->sends() !== []) {
            return $result->sends();
        }

        return $this->scheduler()->normalize($this->nextNodesFor($graph, $nodeId, $result, $state));
    }

    /**
     * @param  array<int, array{node_id: string, result: NodeResult}>  $results
     */
    protected function applySuperstepWrites(array $state, array $results, array $reducers): array
    {
        $channels = [];

        foreach ($results as $record) {
            foreach ($record['result']->writes() as $channel => $value) {
                $channels[$channel] ??= [];
                $channels[$channel][] = $record['node_id'];
            }
        }

        foreach ($channels as $channel => $nodeIds) {
            if (count($nodeIds) > 1 && ! isset($reducers[$channel])) {
                throw new RuntimeException("Concurrent writes to state channel [{$channel}] require an explicit reducer.");
            }
        }

        $reducer = new StateReducer($reducers);

        foreach ($results as $record) {
            $state = $reducer->apply($state, $record['result']->writes());
        }

        return $state;
    }

    /**
     * @param  array<int, array{node_id: string, result: NodeResult}>  $results
     * @param  array<int, Send>  $nextSchedule
     */
    protected function checkpointMetaForResults(array $results, array $nextSchedule): array
    {
        $meta = [];

        if (count($results) === 1) {
            $meta = $results[0]['result']->meta();
        } else {
            $meta['nodes'] = array_map(fn (array $record): array => [
                'node_id' => $record['node_id'],
                'meta' => $record['result']->meta(),
            ], $results);
        }

        return $this->withNextScheduleMeta($meta, $nextSchedule);
    }

    /**
     * @param  array<int, Send>  $nextSchedule
     */
    protected function withNextScheduleMeta(array $meta, array $nextSchedule): array
    {
        if ($nextSchedule === []) {
            return $meta;
        }

        if (! is_array($meta['runtime'] ?? null)) {
            $meta['runtime'] = [];
        }

        if (! is_array($meta['runtime']['schedule'] ?? null)) {
            $meta['runtime']['schedule'] = [];
        }

        $meta['runtime']['schedule']['next'] = $this->scheduler()->serialize($nextSchedule);

        return $meta;
    }

    protected function createTimeTravelRun(array $checkpoint, ?string $threadId, string $mode, array $meta): array
    {
        $sourceRun = $this->runs->find((string) $checkpoint['run_id']);

        return $this->runs->create(
            $checkpoint['graph_key'],
            $checkpoint['graph_version'],
            $threadId ?? $checkpoint['thread_id'],
            $checkpoint['state'],
            array_merge($meta, [
                'time_travel' => [
                    'mode' => $mode,
                    'source_run_id' => $checkpoint['run_id'],
                    'source_checkpoint_id' => $checkpoint['checkpoint_id'],
                ],
                'parent' => [
                    'run_id' => $checkpoint['run_id'],
                    'checkpoint_id' => $checkpoint['checkpoint_id'],
                    'node_id' => null,
                    'depth' => $this->childDepthFor($sourceRun),
                    'relationship' => $mode,
                ],
            ]),
        );
    }

    protected function childDepthFor(?array $parentRun): int
    {
        return max(1, (int) data_get($parentRun, 'meta.parent.depth', 0) + 1);
    }

    protected function createSyntheticCheckpoint(array $run, array $sourceCheckpoint, array $state, array $nextNodes, array $meta, array $nextSchedule = []): array
    {
        if ($nextSchedule !== []) {
            $meta = $this->withNextScheduleMeta($meta, $nextSchedule);
        }

        return $this->transaction(fn () => $this->checkpoints->create([
            'run_id' => $run['public_id'],
            'thread_id' => $run['thread_id'],
            'graph_key' => $sourceCheckpoint['graph_key'],
            'graph_version' => $sourceCheckpoint['graph_version'],
            'parent_checkpoint_id' => $sourceCheckpoint['checkpoint_id'],
            'step' => (int) $sourceCheckpoint['step'],
            'state' => $state,
            'next_nodes' => $nextNodes,
            'completed_nodes' => [],
            'interrupts' => [],
            'meta' => $meta,
        ]));
    }

    protected function completeTimeTravelRun(array $run, array $sourceCheckpoint, string $mode): RunResult
    {
        $checkpoint = $this->createSyntheticCheckpoint(
            $run,
            $sourceCheckpoint,
            $sourceCheckpoint['state'],
            $sourceCheckpoint['next_nodes'] ?? [],
            ['source' => $mode],
        );

        $run = $this->updateRun($run, [
            'status' => 'completed',
            'current_checkpoint_id' => $checkpoint['checkpoint_id'],
        ]);

        return new RunResult($run, $sourceCheckpoint['state']);
    }

    protected function isTerminalNext(array $nextNodes): bool
    {
        return $nextNodes === [] || in_array(StateGraph::END, $nextNodes, true);
    }

    protected function assertRunCanResume(array $run): void
    {
        if (RunStatus::isTerminal($run['status'] ?? null)) {
            throw new RuntimeException("Run [{$run['public_id']}] is {$run['status']} and cannot be resumed.");
        }
    }

    protected function assertStatePatchMatchesSchema(GraphDefinition $graph, array $statePatch, bool $strictKeys = true): void
    {
        (new StateSchemaValidator)->assertPatch($graph->schema(), $statePatch, $strictKeys);
    }

    protected function assertGraphVersionMatches(array $record, GraphDefinition $graph, string $subject): void
    {
        $version = (string) ($record['graph_version'] ?? '');

        if ($version !== $graph->version()) {
            throw new RuntimeException("{$subject} graph version [{$version}] does not match registered graph version [{$graph->version()}].");
        }
    }

    protected function inferReducers(GraphDefinition $graph): array
    {
        $reducers = $graph->reducers();

        foreach ($graph->schema() as $channel => $type) {
            if (! isset($reducers[$channel]) && $type === 'messages') {
                $reducers[$channel] = Reducer::addMessages();
            }
        }

        return $reducers;
    }

    protected function nodeExecutor(): NodeExecutor
    {
        return new NodeExecutor($this->container, $this->memory, $this->traces, $this->tasks, $this->locks, $this->events());
    }

    protected function resumeProtocol(): ResumeProtocol
    {
        return new ResumeProtocol($this->runs, $this->interrupts, $this->scheduler());
    }

    protected function inspector(): RunInspector
    {
        return $this->inspector ??= new RunInspector(
            runs: $this->runs,
            checkpoints: $this->checkpoints,
            writes: $this->writes,
            interrupts: $this->interrupts,
            traces: $this->traces,
        );
    }

    protected function dispatchRunEvent(string $type, GraphEvent $event): void
    {
        $this->events()->dispatch($type, $event);
    }

    protected function events(): RunEventDispatcher
    {
        return $this->events ??= app(RunEventDispatcher::class);
    }

    protected function nodeExecutionStore(): NodeExecutionStore
    {
        return $this->nodeExecutions ??= app(NodeExecutionStore::class);
    }

    protected function scheduler(): RuntimeScheduler
    {
        return new RuntimeScheduler;
    }

    protected function delayScheduler(): DelayScheduler
    {
        return ($this->delaySchedulers ??= app(DelaySchedulerResolver::class))->resolve();
    }
}
