<?php

namespace Heiner\AgentGraph\Runtime;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Heiner\AgentGraph\Contracts\CheckpointStore;
use Heiner\AgentGraph\Contracts\DelayScheduler;
use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Contracts\MemoryStore;
use Heiner\AgentGraph\Contracts\Node;
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
use Heiner\AgentGraph\Events\GraphNodeRetrying;
use Heiner\AgentGraph\Events\GraphNodeStarted;
use Heiner\AgentGraph\Events\GraphResumed;
use Heiner\AgentGraph\Events\GraphRunCancelled;
use Heiner\AgentGraph\Events\GraphRunCompleted;
use Heiner\AgentGraph\Events\GraphRunFailed;
use Heiner\AgentGraph\Events\GraphRunStarted;
use Heiner\AgentGraph\Exceptions\AgentApprovalRequiredException;
use Heiner\AgentGraph\Exceptions\NodeExecutionClaimLostException;
use Heiner\AgentGraph\Graph\GraphDefinition;
use Heiner\AgentGraph\Graph\InterruptContract;
use Heiner\AgentGraph\Graph\RetryPolicy;
use Heiner\AgentGraph\Graph\StateGraph;
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
        $runtimeOptions = RuntimeOptions::from($options);
        $this->assertStatePatchMatchesSchema($graph, $input);
        $meta = $runtimeOptions->applyToMeta($meta);

        $run = $this->runs->create($graph->key(), $graph->version(), $threadId, $input, $meta);
        $this->dispatchRunEvent('run.started', new GraphRunStarted($run['public_id'], $threadId, $graph->key(), payload: ['input' => $input]));

        return $this->continue($graph, $run, $input, $graph->entryNodes(), options: $runtimeOptions);
    }

    public function runSession(GraphDefinition $graph, string $threadId, array $input = [], array $meta = [], RuntimeOptions|array $options = []): RunResult
    {
        return $this->locks->withLock('agent-graph:session:'.$graph->key().':'.$threadId, function () use ($graph, $threadId, $input, $meta, $options): RunResult {
            $active = $this->latestForThreadGraph($threadId, $graph->key());

            if ($active !== null) {
                $snapshot = $this->inspect($active['public_id']);

                if ($snapshot !== null) {
                    return $snapshot->toRunResult();
                }
            }

            return $this->run($graph, $threadId, $input, $meta, $options);
        });
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    public function resume(string $runId, array $payload, array $graphs, bool $strictKeys = false, RuntimeOptions|array $options = [], bool $validateInterruptContract = false): RunResult
    {
        return $this->locks->withLock('agent-graph:run:'.$runId, function () use ($runId, $payload, $graphs, $strictKeys, $validateInterruptContract, $options): RunResult {
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

                if ($interrupt === null && $this->matchesPendingResumeRecovery($run, $resumeInterruptId, $resumePayload)) {
                    return $this->recoverLocked($runId, $graphs);
                }

                $this->assertMatchingPendingInterrupt($runId, $resumeInterruptId, $interrupt);
                $this->assertInterruptContractResponse($interrupt, $resumePayload, $validateInterruptContract);
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
                    $this->runs->update($runId, ['meta' => $runtimeOptions->applyToMeta($run['meta'] ?? [])]);
                }

                return $this->recoverLocked($runId, $graphs);
            }

            $this->assertSubgraphResumeBinding($run, $interrupt, $resumePayload, $graphs);

            $state = array_merge($checkpoint['state'], $resumePayload);
            $schedule = $this->resumeSchedule($checkpoint, $interrupt);
            $next = $this->scheduler()->nodeIds($schedule);
            $updates = ['status' => 'running'];
            $meta = is_array($run['meta'] ?? null) ? $run['meta'] : [];

            if (! $incomingOptions->isDefault()) {
                $meta = $runtimeOptions->applyToMeta($meta);
            }

            $updates['meta'] = $this->withPendingResumeRecovery(
                meta: $meta,
                kind: 'resume',
                interruptId: $resumeInterruptId,
                checkpoint: $checkpoint,
                resumePayload: $resumePayload,
                schedule: $schedule,
            );
            $updates['resume_at'] = null;

            $run = $this->transaction(function () use ($resumeInterruptId, $runId, $payload, $updates): array {
                $this->interrupts->resolvePending($resumeInterruptId, $runId, $payload);

                return $this->runs->update($runId, $updates);
            });

            $this->dispatchRunEvent('run.resumed', new GraphResumed($runId, $run['thread_id'], $graph->key(), payload: $resumePayload));

            return $this->continueLocked($graph, $run, $state, $next, [
                'resume_payload' => $resumePayload,
                'interrupt_id' => $resumeInterruptId,
                'schedule' => $this->scheduler()->serialize($schedule),
                'step' => (int) ($checkpoint['step'] ?? 0),
                'source_checkpoint_id' => $checkpoint['checkpoint_id'] ?? null,
            ], $runtimeOptions);
        });
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    public function resumeWithStateEdit(string $runId, string $interruptId, array $statePatch, array $graphs, ?string $resolvedBy = null): RunResult
    {
        return $this->locks->withLock('agent-graph:run:'.$runId, function () use ($runId, $interruptId, $statePatch, $graphs, $resolvedBy): RunResult {
            $run = $this->runs->find($runId) ?? throw new RuntimeException("Run [{$runId}] was not found.");
            $this->assertRunCanResume($run);
            $graph = $graphs[$run['graph_key']] ?? throw new RuntimeException("Graph [{$run['graph_key']}] is not defined.");
            $this->assertGraphVersionMatches($run, $graph, 'Run');
            $checkpoint = $this->checkpoints->latestForRun($runId) ?? throw new RuntimeException("Run [{$runId}] has no checkpoint.");
            $interrupt = $this->interrupts->pendingForRun($runId);

            if ($interrupt === null && $this->matchesPendingResumeRecovery($run, $interruptId, $statePatch)) {
                return $this->recoverLocked($runId, $graphs);
            }

            $this->assertMatchingPendingInterrupt($runId, $interruptId, $interrupt);

            if (($interrupt['type'] ?? null) !== 'state_edit') {
                throw new InvalidArgumentException("Interrupt [{$interruptId}] is not a state_edit interrupt.");
            }

            $this->assertSubgraphResumeBinding($run, $interrupt, $statePatch, $graphs);
            $this->assertStatePatchMatchesSchema($graph, $statePatch);

            $state = array_merge($checkpoint['state'], $statePatch);
            $schedule = $this->resumeSchedule($checkpoint, $interrupt);
            $next = $this->scheduler()->nodeIds($schedule);
            $response = ['interrupt_id' => $interruptId, 'state' => $statePatch];
            $meta = $this->withPendingResumeRecovery(
                meta: is_array($run['meta'] ?? null) ? $run['meta'] : [],
                kind: 'state_edit',
                interruptId: $interruptId,
                checkpoint: $checkpoint,
                resumePayload: $statePatch,
                schedule: $schedule,
            );
            $run = $this->transaction(function () use ($interruptId, $runId, $response, $resolvedBy, $meta): array {
                $this->interrupts->resolvePending($interruptId, $runId, $response, $resolvedBy);

                return $this->runs->update($runId, [
                    'status' => 'running',
                    'resume_at' => null,
                    'meta' => $meta,
                ]);
            });
            $this->dispatchRunEvent('run.resumed', new GraphResumed($runId, $run['thread_id'], $graph->key(), payload: $statePatch));

            return $this->continueLocked($graph, $run, $state, $next, [
                'resume_payload' => $statePatch,
                'interrupt_id' => $interruptId,
                'schedule' => $this->scheduler()->serialize($schedule),
                'step' => (int) ($checkpoint['step'] ?? 0),
                'source_checkpoint_id' => $checkpoint['checkpoint_id'] ?? null,
            ]);
        });
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    public function recover(string $runId, array $graphs): RunResult
    {
        return $this->locks->withLock(
            'agent-graph:run:'.$runId,
            fn (): RunResult => $this->recoverLocked($runId, $graphs),
        );
    }

    public function cancel(string $runId, array $meta = []): RunResult
    {
        return $this->locks->withLock('agent-graph:run:'.$runId, function () use ($runId, $meta): RunResult {
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

                return $this->runs->update($runId, [
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'resume_at' => null,
                    'meta' => array_merge(
                        $this->withoutPendingResumeRecovery(is_array($run['meta'] ?? null) ? $run['meta'] : []),
                        ['cancelled' => $meta],
                    ),
                ]);
            });

            $checkpoint = $this->checkpoints->latestForRun($runId);
            $this->dispatchRunEvent('run.cancelled', new GraphRunCancelled($runId, $run['thread_id'], $run['graph_key'], payload: $meta));

            return new RunResult($run, $checkpoint['state'] ?? []);
        });
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

        return $this->continue($graph, $run, $checkpoint['state'], $nextNodes, [
            'schedule' => $this->scheduler()->fromCheckpoint($checkpoint),
            'source_checkpoint_id' => $checkpoint['checkpoint_id'],
            'step' => (int) $checkpoint['step'],
        ]);
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
            $run = $this->runs->update($run['public_id'], [
                'status' => 'completed',
                'current_checkpoint_id' => $forkCheckpoint['checkpoint_id'],
            ]);

            return new RunResult($run, $state);
        }

        return $this->continue($graph, $run, $state, $nextNodes, [
            'schedule' => $nextSchedule,
            'source_checkpoint_id' => $forkCheckpoint['checkpoint_id'],
            'step' => (int) $checkpoint['step'],
        ]);
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
        $store = $this->nodeExecutionStore();
        $existing = $store->find($executionId);

        if ($existing === null) {
            return null;
        }

        $existingRun = $this->runs->find((string) $existing['run_id']);

        if ($existingRun === null || RunStatus::isTerminal($existingRun['status'] ?? null)) {
            return $existing;
        }

        $peers = $store->listForRunStep((string) $existing['run_id'], (int) $existing['step']);

        if (array_filter($peers, fn (array $peer): bool => ($peer['status'] ?? null) === 'failed') !== []) {
            $this->continueQueuedSuperstep((string) $existing['run_id'], (int) $existing['step'], $graphs);

            return $store->find($executionId);
        }

        if (in_array($existing['status'], ['pending', 'running'], true) && is_string($existing['checkpoint_id'] ?? null)) {
            $source = $this->checkpoints->find($existing['checkpoint_id']);
            $this->assertCheckpointContinuationIsSafe($existingRun, $source, $peers);
        }

        $execution = $store->claim($executionId, now()->addSeconds((int) config('agent-graph.execution.node_lease_seconds', 300)));

        if ($execution === null) {
            return null;
        }

        if (in_array($execution['status'], ['completed', 'interrupted', 'failed'], true)) {
            $this->dispatchContinueSuperstep((string) $execution['run_id'], (int) $execution['step']);

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

        $payload = [];

        try {
            $result = $this->invokeNode(
                $graph,
                $nodeId,
                $nodeState,
                $run,
                $execution['checkpoint_id'] ?? null,
                is_array($execution['resume_payload'] ?? null) ? $execution['resume_payload'] : null,
                is_string($execution['interrupt_id'] ?? null) ? $execution['interrupt_id'] : null,
            );

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
        } catch (Throwable $exception) {
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
        $this->dispatchContinueSuperstep($run['public_id'], (int) $execution['step']);

        return $updated;
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    public function continueQueuedSuperstep(string $runId, int $step, array $graphs): ?RunResult
    {
        return $this->locks->withLock('agent-graph:run:'.$runId, function () use ($runId, $step, $graphs): ?RunResult {
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

            $this->assertCheckpointContinuationIsSafe($run, $latestCheckpoint, $executions);

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

            if ($interrupt !== null) {
                return new RunResult($run, $state, $interrupt);
            }

            if ($nextSchedule === []) {
                $run = $this->transaction(fn () => $this->runs->update($run['public_id'], [
                    'status' => 'completed',
                    'current_checkpoint_id' => $checkpoint['checkpoint_id'],
                ]));
                $this->dispatchRunEvent('run.completed', new GraphRunCompleted($run['public_id'], $run['thread_id'], $graph->key(), payload: ['state' => $state]));

                return new RunResult($run, $state);
            }

            return $this->queueSuperstepLocked($graph, $run, $state, $nextSchedule, $step, $checkpoint['checkpoint_id'], options: $options);
        });
    }

    protected function continue(GraphDefinition $graph, array $run, array $state, array $nextNodes, array $resumeContext = [], ?RuntimeOptions $options = null): RunResult
    {
        return $this->locks->withLock('agent-graph:run:'.$run['public_id'], function () use ($graph, $run, $state, $nextNodes, $resumeContext, $options): RunResult {
            return $this->continueLocked($graph, $run, $state, $nextNodes, $resumeContext, $options);
        });
    }

    protected function continueLocked(GraphDefinition $graph, array $run, array $state, array $nextNodes, array $resumeContext = [], ?RuntimeOptions $options = null): RunResult
    {
        $options ??= RuntimeOptions::fromRun($run);
        $freshRun = $this->runs->find($run['public_id']) ?? $run;

        if (RunStatus::isTerminal($freshRun['status'] ?? null)) {
            $checkpoint = $this->checkpoints->latestForRun($freshRun['public_id']);

            return new RunResult($freshRun, $checkpoint['state'] ?? $state);
        }

        $run = $freshRun;
        $maxSteps = $options->maxSteps();
        $latestCheckpoint = $this->checkpoints->latestForRun($run['public_id']);
        $step = (int) ($resumeContext['step'] ?? ($latestCheckpoint['step'] ?? 0));
        $checkpointId = $resumeContext['source_checkpoint_id'] ?? ($latestCheckpoint['checkpoint_id'] ?? null);
        $reducers = $this->inferReducers($graph);
        $reducer = new StateReducer($reducers);
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

        if ($this->queuesSupersteps()) {
            return $this->queueSuperstepLocked(
                $graph,
                $run,
                $state,
                $schedule,
                $step,
                $checkpointId,
                $applyResumeContext ? $resumePayload : null,
                $applyResumeContext ? $resumeInterruptId : null,
                $options,
            );
        }

        while ($schedule !== []) {
            if ($step >= $maxSteps) {
                $run = $this->runs->update($run['public_id'], [
                    'status' => 'failed',
                    'error' => RuntimeError::fromMessage('Maximum graph steps exceeded.', code: 'max_steps_exceeded'),
                ]);
                $this->dispatchRunEvent('run.failed', new GraphRunFailed($run['public_id'], $run['thread_id'], $graph->key(), payload: $run['error']));

                return new RunResult($run, $state);
            }

            try {
                $this->scheduler()->assertWithinLimit($schedule);
            } catch (Throwable $exception) {
                return $this->failRun($run, $graph, 'superstep', $state, $exception);
            }

            $baseState = $state;
            $results = [];
            $nextSchedule = [];
            $interrupted = null;

            foreach ($schedule as $scheduleIndex => $scheduledNode) {
                $nodeId = $scheduledNode->node();
                $nodeState = array_merge($baseState, $scheduledNode->input());
                $this->dispatchRunEvent('node.started', new GraphNodeStarted($run['public_id'], $run['thread_id'], $graph->key(), $nodeId));

                try {
                    $result = $this->invokeNode(
                        $graph,
                        $nodeId,
                        $nodeState,
                        $run,
                        $checkpointId,
                        $applyResumeContext ? $resumePayload : null,
                        $applyResumeContext ? $resumeInterruptId : null,
                    );
                } catch (Throwable $exception) {
                    $error = RuntimeError::fromThrowable($exception);
                    $run = $this->runs->update($run['public_id'], ['status' => 'failed', 'error' => $error]);
                    $this->traces->record($run['public_id'], 'node.failed', array_merge(['node' => $nodeId], $error));
                    $this->dispatchRunEvent('node.failed', new GraphNodeFailed($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, $error));
                    $this->dispatchRunEvent('run.failed', new GraphRunFailed($run['public_id'], $run['thread_id'], $graph->key(), payload: $run['error']));

                    return new RunResult($run, $state);
                }

                if ($result->status() === 'failed') {
                    $error = RuntimeError::fromMessage((string) $result->failureMessage(), meta: $result->meta());
                    $run = $this->runs->update($run['public_id'], ['status' => 'failed', 'error' => $error]);
                    $this->traces->record($run['public_id'], 'node.failed', array_merge(['node' => $nodeId], $error));
                    $this->dispatchRunEvent('node.failed', new GraphNodeFailed($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, $run['error']));
                    $this->dispatchRunEvent('run.failed', new GraphRunFailed($run['public_id'], $run['thread_id'], $graph->key(), payload: $run['error']));

                    return new RunResult($run, $state);
                }

                try {
                    $this->assertNodeResultTargetsAreKnown($graph, $nodeId, $result);
                    $this->assertStatePatchMatchesSchema($graph, $result->writes());
                } catch (Throwable $exception) {
                    return $this->failRun($run, $graph, $nodeId, $state, $exception);
                }

                if ($result->status() === 'interrupted') {
                    $interrupted = ['node_id' => $nodeId, 'result' => $result, 'schedule' => $scheduledNode->toArray()];
                }

                $this->recordNodeExecutionIfEnabled($run['public_id'], $step + 1, $scheduleIndex, $nodeId, $result);

                $branchState = $reducer->apply($nodeState, $result->writes());
                $results[] = ['node_id' => $nodeId, 'result' => $result];
                array_push($nextSchedule, ...$this->nextScheduleFor($graph, $nodeId, $result, $branchState));
            }

            if ($interrupted !== null && count($schedule) > 1) {
                return $this->failRun(
                    $run,
                    $graph,
                    (string) $interrupted['node_id'],
                    $state,
                    new RuntimeException('Parallel interrupts are not supported in the same superstep. Route human review after fan-in.'),
                );
            }

            try {
                $state = $this->applySuperstepWrites($state, $results, $reducers);
            } catch (Throwable $exception) {
                return $this->failRun($run, $graph, 'superstep', $state, $exception);
            }

            $nextSchedule = $this->scheduler()->normalize($nextSchedule);
            $step++;

            try {
                $wait = $interrupted !== null ? [
                    'node_id' => $interrupted['node_id'],
                    'type' => $interrupted['result']->interruptType(),
                    'payload' => $interrupted['result']->interruptPayload(),
                    'expires_at' => $interrupted['result']->interruptPolicy()?->expiresAtValue(),
                    'schedule' => $interrupted['schedule'],
                ] : null;
                [$checkpoint, $run, $interrupt, $resumeAt] = $this->persistSuperstepCheckpoint(
                    $graph, $run, $state, $results, $nextSchedule, $step, $checkpointId, $wait,
                );
            } catch (Throwable $exception) {
                return $this->failRun($run, $graph, 'superstep', $baseState, $exception);
            }

            $checkpointId = $checkpoint['checkpoint_id'];
            $this->notifySuperstepCommitted($graph, $run, $results, $checkpoint, $interrupt, $resumeAt);

            if ($interrupt !== null) {
                return new RunResult($run, $state, $interrupt);
            }

            if ($nextSchedule === []) {
                $run = $this->transaction(fn () => $this->runs->update($run['public_id'], [
                    'status' => 'completed',
                    'current_checkpoint_id' => $checkpointId,
                ]));
                $this->dispatchRunEvent('run.completed', new GraphRunCompleted($run['public_id'], $run['thread_id'], $graph->key(), payload: ['state' => $state]));

                return new RunResult($run, $state);
            }

            $schedule = $nextSchedule;
            $applyResumeContext = false;
        }

        $run = $this->transaction(fn () => $this->runs->update($run['public_id'], ['status' => 'completed', 'current_checkpoint_id' => $checkpointId]));
        $this->dispatchRunEvent('run.completed', new GraphRunCompleted($run['public_id'], $run['thread_id'], $graph->key(), payload: ['state' => $state]));

        return new RunResult($run, $state);
    }

    /**
     * @param  array<int, Send>  $schedule
     */
    protected function queueSuperstepLocked(GraphDefinition $graph, array $run, array $state, array $schedule, int $currentStep, ?string $checkpointId, ?array $resumePayload = null, ?string $interruptId = null, ?RuntimeOptions $options = null): RunResult
    {
        $options ??= RuntimeOptions::fromRun($run);

        if ($schedule === []) {
            $run = $this->transaction(fn () => $this->runs->update($run['public_id'], [
                'status' => 'completed',
                'current_checkpoint_id' => $checkpointId,
            ]));
            $this->dispatchRunEvent('run.completed', new GraphRunCompleted($run['public_id'], $run['thread_id'], $graph->key(), payload: ['state' => $state]));

            return new RunResult($run, $state);
        }

        if ($currentStep >= $options->maxSteps()) {
            $run = $this->runs->update($run['public_id'], [
                'status' => 'failed',
                'error' => RuntimeError::fromMessage('Maximum graph steps exceeded.', code: 'max_steps_exceeded'),
            ]);
            $this->dispatchRunEvent('run.failed', new GraphRunFailed($run['public_id'], $run['thread_id'], $graph->key(), payload: $run['error']));

            return new RunResult($run, $state);
        }

        try {
            $this->scheduler()->assertWithinLimit($schedule);
        } catch (Throwable $exception) {
            return $this->failRun($run, $graph, 'superstep', $state, $exception);
        }

        $step = $currentStep + 1;
        $store = $this->nodeExecutionStore();
        $existing = $store->listForRunStep($run['public_id'], $step);

        if ($existing !== []) {
            $this->redispatchQueuedFrontier($run['public_id'], $step, $existing);

            return new RunResult($this->runs->find($run['public_id']) ?? $run, $state);
        }

        $executions = $this->transaction(function () use ($store, $run, $checkpointId, $step, $schedule, $state, $resumePayload, $interruptId): array {
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

            $this->clearPendingResumeRecovery($run['public_id']);

            return $executions;
        });

        foreach ($executions as $execution) {
            $this->dispatchNodeExecution((string) $execution['execution_id']);
        }

        return new RunResult($this->runs->find($run['public_id']) ?? $run, $state);
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

            $this->traces->record($run['public_id'], 'checkpoint.created', [
                'checkpoint_id' => $checkpoint['checkpoint_id'],
                'nodes' => array_column($results, 'node_id'),
            ]);
            $run = $this->clearPendingResumeRecovery($run['public_id']) ?? $run;
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
                    'expires_at' => $wait['expires_at'] ?? null,
                ]);

                $run = $this->runs->update($run['public_id'], [
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

    protected function failQueuedRun(array $run, GraphDefinition $graph, string $nodeId, array $error): void
    {
        $this->locks->withLock('agent-graph:run:'.$run['public_id'], function () use ($run, $graph, $nodeId, $error): void {
            $this->failQueuedRunLocked($run, $graph, $nodeId, $error);
        });
    }

    protected function failQueuedExecution(array $execution, string $claimToken, array $run, GraphDefinition $graph, array $error): ?array
    {
        $store = $this->nodeExecutionStore();

        try {
            $failed = $store->fail($execution['execution_id'], $claimToken, $error);
        } catch (NodeExecutionClaimLostException) {
            return $store->find($execution['execution_id']);
        }

        $this->failQueuedRun($run, $graph, $execution['node_id'], $error);

        return $failed;
    }

    protected function failQueuedRunLocked(array $run, GraphDefinition $graph, string $nodeId, array $error): void
    {
        $run = $this->runs->find($run['public_id']);

        if ($run === null || RunStatus::isTerminal($run['status'] ?? null)) {
            return;
        }

        $run = $this->transaction(fn () => $this->runs->update($run['public_id'], ['status' => 'failed', 'error' => $error]));
        $this->traces->record($run['public_id'], 'node.failed', array_merge(['node' => $nodeId], $error));
        $this->dispatchRunEvent('node.failed', new GraphNodeFailed($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, $error));
        $this->dispatchRunEvent('run.failed', new GraphRunFailed($run['public_id'], $run['thread_id'], $graph->key(), payload: $run['error']));
    }

    protected function queuesSupersteps(): bool
    {
        return config('agent-graph.execution.mode', 'sync') === 'queued_supersteps';
    }

    protected function invokeNode(GraphDefinition $graph, string $nodeId, array $state, array $run, ?string $checkpointId, ?array $resumePayload = null, ?string $interruptId = null): NodeResult
    {
        $node = $graph->node($nodeId);
        $instance = is_string($node) ? $this->container->make($node) : $node;

        if (! $instance instanceof Node && ! is_callable($instance)) {
            throw new RuntimeException("Node [{$nodeId}] is not invokable.");
        }

        $retryPolicy = $graph->nodePolicy($nodeId)->retryPolicy();
        $timeoutPolicy = $graph->nodePolicy($nodeId)->timeoutPolicy();
        $concurrencyPolicy = $graph->nodePolicy($nodeId)->concurrencyPolicy();

        $invoke = function () use ($instance, $graph, $nodeId, $state, $run, $checkpointId, $resumePayload, $interruptId, $retryPolicy): NodeResult {
            if ($retryPolicy === null) {
                return $this->invokeNodeOnce($instance, $graph, $nodeId, $state, $run, $checkpointId, $resumePayload, $interruptId);
            }

            $attempt = 0;
            $failedAttempts = 0;

            while (true) {
                $attempt++;
                $context = $this->nodeContext($graph, $nodeId, $state, $run, $checkpointId, $resumePayload, $interruptId);

                try {
                    $result = $this->callNode($instance, $context);

                    return $this->withRetryMeta($result, $retryPolicy, $attempt, $failedAttempts);
                } catch (Throwable $exception) {
                    $failedAttempts++;

                    if ($exception instanceof AgentApprovalRequiredException
                        || $attempt >= $retryPolicy->maxAttempts()
                        || ! $retryPolicy->shouldRetry($exception, $attempt, $context)) {
                        throw $exception;
                    }

                    $delayMs = $retryPolicy->delayForAttempt($attempt);
                    $payload = [
                        'node' => $nodeId,
                        'attempt' => $attempt,
                        'next_attempt' => $attempt + 1,
                        'max_attempts' => $retryPolicy->maxAttempts(),
                        'delay_ms' => $delayMs,
                        'error' => [
                            'message' => $exception->getMessage(),
                            'exception_class' => $exception::class,
                            'code' => $exception->getCode(),
                        ],
                    ];

                    $this->traces->record($run['public_id'], 'node.retrying', $payload);
                    $this->dispatchRunEvent('node.retrying', new GraphNodeRetrying($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, $payload));

                    if ($delayMs > 0) {
                        usleep($delayMs * 1000);
                    }
                }
            }
        };

        $timed = function () use ($invoke, $timeoutPolicy, $nodeId): NodeResult {
            $started = microtime(true);
            $result = $invoke();

            if ($timeoutPolicy !== null && (microtime(true) - $started) > $timeoutPolicy->seconds()) {
                throw new RuntimeException("Node [{$nodeId}] timed out after {$timeoutPolicy->seconds()} seconds.");
            }

            return $result;
        };

        if ($concurrencyPolicy !== null && $concurrencyPolicy->limit() === 1) {
            $key = $concurrencyPolicy->key() ?? 'agent-graph:node:'.$graph->key().':'.$nodeId;

            return $this->locks->withLock($key, $timed);
        }

        return $timed();
    }

    protected function invokeNodeOnce(mixed $instance, GraphDefinition $graph, string $nodeId, array $state, array $run, ?string $checkpointId, ?array $resumePayload = null, ?string $interruptId = null): NodeResult
    {
        return $this->callNode(
            $instance,
            $this->nodeContext($graph, $nodeId, $state, $run, $checkpointId, $resumePayload, $interruptId),
        );
    }

    protected function nodeContext(GraphDefinition $graph, string $nodeId, array $state, array $run, ?string $checkpointId, ?array $resumePayload = null, ?string $interruptId = null): NodeContext
    {
        return new NodeContext(
            state: $state,
            runId: $run['public_id'],
            threadId: $run['thread_id'],
            nodeId: $nodeId,
            checkpointId: $checkpointId,
            graphMeta: ['key' => $graph->key(), 'version' => $graph->version()],
            memory: $this->memory,
            traces: $this->traces,
            tasks: new TaskRunner($this->tasks, $run['public_id'], $nodeId, $checkpointId),
            resumePayload: $resumePayload,
            interruptId: $interruptId,
        );
    }

    protected function callNode(mixed $instance, NodeContext $context): NodeResult
    {
        if ($instance instanceof Node || is_callable($instance)) {
            $result = $instance($context);

            return is_array($result) ? NodeResult::write($result) : $result;
        }

        throw new RuntimeException("Node [{$context->nodeId()}] is not invokable.");
    }

    protected function withRetryMeta(NodeResult $result, RetryPolicy $retryPolicy, int $attempts, int $failedAttempts): NodeResult
    {
        $meta = array_replace_recursive($result->meta(), [
            'runtime' => [
                'retry' => [
                    'attempts' => $attempts,
                    'max_attempts' => $retryPolicy->maxAttempts(),
                    'failed_attempts' => $failedAttempts,
                ],
            ],
        ]);

        return $result->withMeta($meta);
    }

    protected function failRun(array $run, GraphDefinition $graph, string $nodeId, array $state, Throwable $exception): RunResult
    {
        $error = RuntimeError::fromThrowable($exception);
        $run = $this->transaction(fn () => $this->runs->update($run['public_id'], [
            'status' => 'failed',
            'error' => $error,
        ]));

        $this->traces->record($run['public_id'], 'node.failed', array_merge(['node' => $nodeId], $error));
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
    protected function recoverLocked(string $runId, array $graphs): RunResult
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
        $executionStore = $this->nodeExecutions ?? ($this->queuesSupersteps() ? $this->nodeExecutionStore() : null);
        $executions = $executionStore?->listForRunStep($runId, $queuedStep) ?? [];
        $pending = data_get($run, 'meta.runtime.recovery.pending_resume');

        $this->assertCheckpointContinuationIsSafe($run, $checkpoint, $executions);

        if ($executions !== []) {
            $this->redispatchQueuedFrontier($runId, $queuedStep, $executions);

            return $this->inspect($runId)?->toRunResult() ?? new RunResult($run, $state);
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

        return $this->continueLocked(
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

    protected function assertCheckpointContinuationIsSafe(array $run, ?array $checkpoint, array $executions): void
    {
        if ($checkpoint === null || is_array(data_get($run, 'meta.runtime.recovery.pending_resume'))) {
            return;
        }

        $schedule = $this->scheduler()->fromCheckpoint($checkpoint);
        $nextNodes = array_values(array_filter(
            $checkpoint['next_nodes'] ?? [],
            fn (string $nodeId): bool => $nodeId !== StateGraph::END,
        ));
        $inconsistent = $this->scheduler()->nodeIds($schedule) !== $nextNodes;
        $waiting = is_array(data_get($checkpoint, 'meta.runtime.wait'));

        if (($inconsistent || $waiting) && ! $this->hasAcceptedQueuedResume($checkpoint, $executions)) {
            if ($inconsistent) {
                throw new RuntimeException("Run [{$run['public_id']}] has an inconsistent recovery schedule; reconcile the checkpoint before recovery.");
            }

            throw new RuntimeException("Run [{$run['public_id']}] has a wait checkpoint without a pending interrupt or accepted resume; reconciliation is required.");
        }
    }

    protected function hasAcceptedQueuedResume(array $checkpoint, array $executions): bool
    {
        if ($executions === [] || array_column($executions, 'node_id') !== ($checkpoint['next_nodes'] ?? [])) {
            return false;
        }

        foreach ($executions as $execution) {
            $interruptId = $execution['interrupt_id'] ?? null;

            if (! is_string($interruptId) || $interruptId === ''
                || ($execution['checkpoint_id'] ?? null) !== $checkpoint['checkpoint_id']
                || ! is_array($execution['resume_payload'] ?? null)) {
                return false;
            }

            $interrupt = $this->interrupts->find($interruptId);

            if (($interrupt['status'] ?? null) !== 'resolved'
                || ($interrupt['run_id'] ?? null) !== $checkpoint['run_id']
                || ($interrupt['checkpoint_id'] ?? null) !== $checkpoint['checkpoint_id']
                || ($interrupt['node_id'] ?? null) !== $execution['node_id']
                || ! is_array($interrupt['response'] ?? null)) {
                return false;
            }

            $response = $interrupt['response'];
            unset($response['interrupt_id']);

            $expectedHash = $this->resumePayloadHash($execution['resume_payload']);
            $matches = hash_equals($this->resumePayloadHash($response), $expectedHash);

            if (! $matches && ($interrupt['type'] ?? null) === 'state_edit' && is_array($response['state'] ?? null)) {
                $matches = hash_equals($this->resumePayloadHash($response['state']), $expectedHash);
            }

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $resumePayload
     */
    protected function matchesPendingResumeRecovery(array $run, string $interruptId, array $resumePayload): bool
    {
        $pending = data_get($run, 'meta.runtime.recovery.pending_resume');

        return is_array($pending)
            && hash_equals((string) ($pending['interrupt_id'] ?? ''), $interruptId)
            && hash_equals(
                (string) ($pending['resume_payload_hash'] ?? ''),
                $this->resumePayloadHash($resumePayload),
            );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $checkpoint
     * @param  array<string, mixed>  $resumePayload
     * @param  array<int, Send>  $schedule
     * @return array<string, mixed>
     */
    protected function withPendingResumeRecovery(
        array $meta,
        string $kind,
        string $interruptId,
        array $checkpoint,
        array $resumePayload,
        array $schedule,
    ): array {
        data_set($meta, 'runtime.recovery.pending_resume', [
            'kind' => $kind,
            'interrupt_id' => $interruptId,
            'source_checkpoint_id' => $checkpoint['checkpoint_id'] ?? null,
            'step' => (int) ($checkpoint['step'] ?? 0),
            'resume_payload' => $resumePayload,
            'resume_payload_hash' => $this->resumePayloadHash($resumePayload),
            'schedule' => $this->scheduler()->serialize($schedule),
            'accepted_at' => now()->toISOString(),
        ]);

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $resumePayload
     */
    protected function resumePayloadHash(array $resumePayload): string
    {
        return hash('sha256', json_encode(
            $resumePayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function withoutPendingResumeRecovery(array $meta): array
    {
        if (is_array($meta['runtime']['recovery'] ?? null)) {
            unset($meta['runtime']['recovery']['pending_resume']);

            if ($meta['runtime']['recovery'] === []) {
                unset($meta['runtime']['recovery']);
            }
        }

        if (is_array($meta['runtime'] ?? null) && $meta['runtime'] === []) {
            unset($meta['runtime']);
        }

        return $meta;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function clearPendingResumeRecovery(string $runId): ?array
    {
        $run = $this->runs->find($runId);

        if ($run === null) {
            return null;
        }

        $meta = is_array($run['meta'] ?? null) ? $run['meta'] : [];

        if (data_get($meta, 'runtime.recovery.pending_resume') === null) {
            return $run;
        }

        return $this->runs->update($runId, [
            'meta' => $this->withoutPendingResumeRecovery($meta),
        ]);
    }

    protected function transaction(callable $callback): mixed
    {
        return $this->container->make('db')->connection(AgentGraphDatabase::connectionName())->transaction($callback);
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

        $run = $this->runs->update($run['public_id'], [
            'status' => 'completed',
            'current_checkpoint_id' => $checkpoint['checkpoint_id'],
        ]);

        return new RunResult($run, $sourceCheckpoint['state']);
    }

    protected function isTerminalNext(array $nextNodes): bool
    {
        return $nextNodes === [] || in_array(StateGraph::END, $nextNodes, true);
    }

    protected function assertMatchingPendingInterrupt(string $runId, string $interruptId, ?array $interrupt): void
    {
        if ($interrupt === null) {
            throw new InvalidArgumentException("Run [{$runId}] has no pending interrupt.");
        }

        if (($interrupt['interrupt_id'] ?? null) !== $interruptId) {
            throw new InvalidArgumentException("Interrupt [{$interruptId}] does not match the pending interrupt for run [{$runId}].");
        }

        if (($interrupt['expires_at'] ?? null) !== null && now()->greaterThanOrEqualTo($interrupt['expires_at'])) {
            throw new InvalidArgumentException("Interrupt [{$interruptId}] has expired and cannot be resumed.");
        }
    }

    /** @return array<int, Send> */
    protected function resumeSchedule(array $checkpoint, array $interrupt): array
    {
        $schedule = $this->scheduler()->fromCheckpoint($checkpoint);

        if (($interrupt['checkpoint_id'] ?? null) !== $checkpoint['checkpoint_id']
            || ($interrupt['run_id'] ?? null) !== $checkpoint['run_id']
            || $this->scheduler()->nodeIds($schedule) !== [$interrupt['node_id']]) {
            throw new InvalidArgumentException('The pending interrupt does not match the checkpoint continuation; reconciliation is required.');
        }

        return $schedule;
    }

    protected function assertSubgraphResumeBinding(array $run, ?array $interrupt, array $payload, array $graphs, array $ancestors = []): void
    {
        if (($interrupt['type'] ?? null) !== 'subgraph') {
            if (array_key_exists('child_run_id', $payload) || array_key_exists('child_interrupt_id', $payload)) {
                throw new InvalidArgumentException('Child run identities are only valid for a pending subgraph interrupt.');
            }

            return;
        }

        $binding = is_array($interrupt['payload'] ?? null) ? $interrupt['payload'] : [];
        $childRunId = $binding['child_run_id'] ?? null;
        $childInterruptId = $binding['child_interrupt_id'] ?? null;

        if (! is_string($childRunId) || $childRunId === ''
            || ! is_string($childInterruptId) || $childInterruptId === ''
            || ($payload['child_run_id'] ?? null) !== $childRunId
            || ($payload['child_interrupt_id'] ?? null) !== $childInterruptId) {
            throw new InvalidArgumentException('Child run identities must match the pending parent subgraph interrupt.');
        }

        $child = $this->runs->find($childRunId);

        if ($child === null
            || $childRunId === $run['public_id']
            || in_array($childRunId, $ancestors, true)
            || data_get($child, 'meta.parent.relationship') !== 'subgraph'
            || data_get($child, 'meta.parent.run_id') !== $run['public_id']
            || data_get($child, 'meta.parent.node_id') !== ($interrupt['node_id'] ?? null)) {
            throw new InvalidArgumentException("Child run [{$childRunId}] is not bound to the pending parent node.");
        }

        if (RunStatus::isTerminal($child['status'] ?? null)) {
            return;
        }

        $childInterrupt = $this->interrupts->pendingForRun($childRunId);
        $accepted = data_get($child, 'meta.runtime.recovery.pending_resume.resume_payload');
        $accepted = is_array($accepted) ? $accepted : [];
        $nested = ($childInterrupt['type'] ?? null) === 'subgraph'
            ? ($childInterrupt['payload'] ?? [])
            : ($childInterrupt === null ? $accepted : []);
        $childPayload = $payload;
        unset($childPayload['child_run_id'], $childPayload['child_interrupt_id']);

        foreach (['child_run_id', 'child_interrupt_id'] as $key) {
            if (is_string($nested[$key] ?? null)) {
                $childPayload[$key] = $nested[$key];
            }
        }

        $childGraph = $graphs[$child['graph_key']] ?? throw new RuntimeException("Graph [{$child['graph_key']}] is not defined.");
        $this->assertGraphVersionMatches($child, $childGraph, 'Child run');
        $this->assertStatePatchMatchesSchema($childGraph, $childPayload, strictKeys: false);

        if (($childInterrupt['interrupt_id'] ?? null) === $childInterruptId) {
            $this->assertSubgraphResumeBinding($child, $childInterrupt, $childPayload, $graphs, [...$ancestors, $run['public_id']]);

            return;
        }

        if ($childInterrupt === null && ($child['status'] ?? null) === 'running'
            && data_get($child, 'meta.runtime.recovery.pending_resume.interrupt_id') === $childInterruptId) {
            // Preserve the field order used by existing accepted payload hashes.
            $childPayload = array_replace(array_intersect_key($accepted, $childPayload), $childPayload);

            if ($this->matchesPendingResumeRecovery($child, $childInterruptId, $childPayload)) {
                return;
            }

            throw new InvalidArgumentException("Child resume payload does not match the accepted response for child run [{$childRunId}].");
        }

        throw new InvalidArgumentException("Child interrupt [{$childInterruptId}] is no longer the pending interrupt for child run [{$childRunId}].");
    }

    protected function assertInterruptContractResponse(?array $interrupt, array $response, bool $validateInterruptContract): void
    {
        if (! $validateInterruptContract || $interrupt === null || ! is_array($interrupt['payload'] ?? null)) {
            return;
        }

        $payload = $interrupt['payload'];

        if (! InterruptContract::isContractPayload($payload)) {
            return;
        }

        InterruptContract::fromArray($payload, (string) ($interrupt['type'] ?? 'input'))
            ->assertResponse($response);
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

    protected function recordNodeExecutionIfEnabled(string $runId, int $step, int $scheduleIndex, string $nodeId, NodeResult $result): void
    {
        if (config('agent-graph.execution.mode', 'sync') !== 'queued_supersteps') {
            return;
        }

        $this->nodeExecutionStore()->record([
            'run_id' => $runId,
            'step' => $step,
            'schedule_index' => $scheduleIndex,
            'node_id' => $nodeId,
            'status' => $result->status(),
            'writes' => $result->writes(),
            'next_schedule' => array_map(fn (Send $send): array => $send->toArray(), $result->sends()),
            'interrupt' => $result->status() === 'interrupted'
                ? ['type' => $result->interruptType(), 'payload' => $result->interruptPayload()]
                : null,
            'meta' => $result->meta(),
        ]);
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
