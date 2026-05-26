<?php

namespace Heiner\AgentGraph\Runtime;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Heiner\AgentGraph\Contracts\CheckpointStore;
use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Contracts\MemoryStore;
use Heiner\AgentGraph\Contracts\Node;
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
use Heiner\AgentGraph\Graph\GraphDefinition;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Queue\ContinueDelayedGraphJob;
use Heiner\AgentGraph\State\Reducer;
use Heiner\AgentGraph\State\StateReducer;
use Heiner\AgentGraph\State\StateSchemaValidator;
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
        protected InterruptStore $interrupts,
        protected MemoryStore $memory,
        protected TraceStore $traces,
        protected LockProvider $locks,
        protected ?RunInspector $inspector = null,
        protected ?RunEventDispatcher $events = null,
    ) {}

    public function run(GraphDefinition $graph, string $threadId, array $input = [], array $meta = []): RunResult
    {
        $this->assertStatePatchMatchesSchema($graph, $input);

        $run = $this->runs->create($graph->key(), $graph->version(), $threadId, $input, $meta);
        $this->dispatchRunEvent('run.started', new GraphRunStarted($run['public_id'], $threadId, $graph->key(), payload: ['input' => $input]));

        return $this->continue($graph, $run, $input, [$graph->entryNode()]);
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    public function resume(string $runId, array $payload, array $graphs): RunResult
    {
        $run = $this->runs->find($runId) ?? throw new RuntimeException("Run [{$runId}] was not found.");
        $graph = $graphs[$run['graph_key']] ?? throw new RuntimeException("Graph [{$run['graph_key']}] is not defined.");
        $this->assertGraphVersionMatches($run, $graph, 'Run');
        $checkpoint = $this->checkpoints->latestForRun($runId) ?? throw new RuntimeException("Run [{$runId}] has no checkpoint.");
        $interrupt = $this->interrupts->pendingForRun($runId);

        $resumePayload = $payload;
        unset($resumePayload['interrupt_id']);
        $this->assertStatePatchMatchesSchema($graph, $resumePayload, strictKeys: false);

        if (isset($payload['interrupt_id'])) {
            $this->assertMatchingPendingInterrupt($runId, (string) $payload['interrupt_id'], $interrupt);
            $this->interrupts->resolve($payload['interrupt_id'], $payload);
        } elseif ($interrupt !== null && in_array($run['status'], ['interrupted', 'delayed'], true)) {
            throw new InvalidArgumentException("Run [{$runId}] requires interrupt_id to resume.");
        }

        $state = array_merge($checkpoint['state'], $resumePayload);
        $next = $checkpoint['next_nodes'] ?: [$graph->entryNode()];
        $run = $this->runs->update($runId, ['status' => 'running']);
        $this->dispatchRunEvent('run.resumed', new GraphResumed($runId, $run['thread_id'], $graph->key(), payload: $resumePayload));

        return $this->continue($graph, $run, $state, $next);
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    public function resumeWithStateEdit(string $runId, string $interruptId, array $statePatch, array $graphs, ?string $resolvedBy = null): RunResult
    {
        $run = $this->runs->find($runId) ?? throw new RuntimeException("Run [{$runId}] was not found.");
        $graph = $graphs[$run['graph_key']] ?? throw new RuntimeException("Graph [{$run['graph_key']}] is not defined.");
        $this->assertGraphVersionMatches($run, $graph, 'Run');
        $checkpoint = $this->checkpoints->latestForRun($runId) ?? throw new RuntimeException("Run [{$runId}] has no checkpoint.");
        $interrupt = $this->interrupts->pendingForRun($runId);

        $this->assertMatchingPendingInterrupt($runId, $interruptId, $interrupt);

        if (($interrupt['type'] ?? null) !== 'state_edit') {
            throw new InvalidArgumentException("Interrupt [{$interruptId}] is not a state_edit interrupt.");
        }

        $this->assertStatePatchMatchesSchema($graph, $statePatch);

        $this->interrupts->resolve(
            $interruptId,
            ['interrupt_id' => $interruptId, 'state' => $statePatch],
            $resolvedBy,
        );

        $state = array_merge($checkpoint['state'], $statePatch);
        $next = $checkpoint['next_nodes'] ?: [$graph->entryNode()];
        $run = $this->runs->update($runId, ['status' => 'running']);
        $this->dispatchRunEvent('run.resumed', new GraphResumed($runId, $run['thread_id'], $graph->key(), payload: $statePatch));

        return $this->continue($graph, $run, $state, $next);
    }

    public function cancel(string $runId, array $meta = []): RunResult
    {
        $run = $this->runs->find($runId) ?? throw new RuntimeException("Run [{$runId}] was not found.");
        $run = $this->runs->update($runId, [
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'meta' => array_merge($run['meta'] ?? [], ['cancelled' => $meta]),
        ]);

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

    public function timeTravelChildren(string $checkpointId, int $limit = 50): array
    {
        return $this->runs->listTimeTravelChildren($checkpointId, $limit);
    }

    protected function continue(GraphDefinition $graph, array $run, array $state, array $nextNodes, array $resumeContext = []): RunResult
    {
        return $this->locks->withLock('agent-graph:run:'.$run['public_id'], function () use ($graph, $run, $state, $nextNodes, $resumeContext) {
            $maxSteps = (int) config('agent-graph.max_steps', 100);
            $latestCheckpoint = $this->checkpoints->latestForRun($run['public_id']);
            $step = (int) ($resumeContext['step'] ?? ($latestCheckpoint['step'] ?? 0));
            $checkpointId = $resumeContext['source_checkpoint_id'] ?? ($latestCheckpoint['checkpoint_id'] ?? null);
            $reducers = $this->inferReducers($graph);
            $reducer = new StateReducer($reducers);
            $schedule = is_array($resumeContext['schedule'] ?? null)
                ? $this->scheduler()->normalize($resumeContext['schedule'])
                : $this->scheduler()->normalize($nextNodes);

            while ($schedule !== []) {
                if ($step >= $maxSteps) {
                    $run = $this->runs->update($run['public_id'], ['status' => 'failed', 'error' => ['message' => 'Maximum graph steps exceeded.']]);
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

                foreach ($schedule as $scheduledNode) {
                    $nodeId = $scheduledNode->node();
                    $nodeState = array_merge($baseState, $scheduledNode->input());
                    $this->dispatchRunEvent('node.started', new GraphNodeStarted($run['public_id'], $run['thread_id'], $graph->key(), $nodeId));

                    try {
                        $result = $this->invokeNode($graph, $nodeId, $nodeState, $run, $checkpointId);
                    } catch (Throwable $exception) {
                        $run = $this->runs->update($run['public_id'], ['status' => 'failed', 'error' => ['message' => $exception->getMessage()]]);
                        $this->traces->record($run['public_id'], 'node.failed', ['node' => $nodeId, 'message' => $exception->getMessage()]);
                        $this->dispatchRunEvent('node.failed', new GraphNodeFailed($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, ['message' => $exception->getMessage()]));
                        $this->dispatchRunEvent('run.failed', new GraphRunFailed($run['public_id'], $run['thread_id'], $graph->key(), payload: $run['error']));

                        return new RunResult($run, $state);
                    }

                    if ($result->status() === 'failed') {
                        $run = $this->runs->update($run['public_id'], ['status' => 'failed', 'error' => ['message' => $result->failureMessage(), 'meta' => $result->meta()]]);
                        $this->traces->record($run['public_id'], 'node.failed', ['node' => $nodeId, 'message' => $result->failureMessage(), 'meta' => $result->meta()]);
                        $this->dispatchRunEvent('node.failed', new GraphNodeFailed($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, $run['error']));
                        $this->dispatchRunEvent('run.failed', new GraphRunFailed($run['public_id'], $run['thread_id'], $graph->key(), payload: $run['error']));

                        return new RunResult($run, $state);
                    }

                    try {
                        $this->assertStatePatchMatchesSchema($graph, $result->writes());
                    } catch (Throwable $exception) {
                        return $this->failRun($run, $graph, $nodeId, $state, $exception);
                    }

                    if ($result->status() === 'interrupted') {
                        $interrupted = ['node_id' => $nodeId, 'result' => $result];
                    }

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
                $nextNodes = $this->scheduler()->nodeIds($nextSchedule);
                $step++;

                try {
                    $checkpointMeta = $this->checkpointMetaForResults($results, $nextSchedule);
                    $checkpoint = $this->transaction(fn () => tap($this->checkpoints->create([
                        'run_id' => $run['public_id'],
                        'thread_id' => $run['thread_id'],
                        'graph_key' => $graph->key(),
                        'graph_version' => $graph->version(),
                        'parent_checkpoint_id' => $checkpointId,
                        'step' => $step,
                        'state' => $state,
                        'next_nodes' => $interrupted !== null ? [(string) $interrupted['node_id']] : $nextNodes,
                        'completed_nodes' => array_map(fn (array $record): string => $record['node_id'], $results),
                        'interrupts' => [],
                        'meta' => $checkpointMeta,
                    ]), function (array $checkpoint) use ($run, $results): void {
                        foreach ($results as $record) {
                            /** @var NodeResult $result */
                            $result = $record['result'];
                            $this->writes->createMany($run['public_id'], $checkpoint['checkpoint_id'], $record['node_id'], $result->writes(), $result->meta());
                        }

                        $this->traces->record($run['public_id'], 'checkpoint.created', [
                            'checkpoint_id' => $checkpoint['checkpoint_id'],
                            'nodes' => array_map(fn (array $record): string => $record['node_id'], $results),
                        ]);
                    }));
                } catch (Throwable $exception) {
                    return $this->failRun($run, $graph, 'superstep', $state, $exception);
                }

                $checkpointId = $checkpoint['checkpoint_id'];
                foreach ($results as $record) {
                    /** @var NodeResult $result */
                    $result = $record['result'];
                    $this->dispatchRunEvent('node.completed', new GraphNodeCompleted($run['public_id'], $run['thread_id'], $graph->key(), $record['node_id'], ['writes' => $result->writes()]));
                    $this->dispatchRunEvent('checkpoint.created', new GraphCheckpointCreated($run['public_id'], $run['thread_id'], $graph->key(), $record['node_id'], ['checkpoint_id' => $checkpointId]));
                }

                if ($interrupted !== null) {
                    /** @var NodeResult $result */
                    $result = $interrupted['result'];
                    $nodeId = (string) $interrupted['node_id'];

                    try {
                        [$interrupt, $run, $resumeAt] = $this->transaction(function () use ($run, $checkpointId, $nodeId, $result): array {
                            $payload = $result->interruptPayload();
                            $status = $result->interruptType() === 'delay' ? 'delayed' : 'interrupted';
                            $resumeAt = null;

                            if ($status === 'delayed') {
                                $resumeAt = $this->normaliseResumeAt($payload['resume_at'] ?? null);
                                $payload['resume_at'] = $resumeAt->toJSON();
                            }

                            $interrupt = $this->interrupts->create([
                                'run_id' => $run['public_id'],
                                'checkpoint_id' => $checkpointId,
                                'node_id' => $nodeId,
                                'type' => $result->interruptType(),
                                'payload' => $payload,
                            ]);

                            $updates = [
                                'status' => $status,
                                'current_checkpoint_id' => $checkpointId,
                            ];

                            if ($resumeAt instanceof CarbonImmutable) {
                                $updates['resume_at'] = $resumeAt;
                            }

                            return [$interrupt, $this->runs->update($run['public_id'], $updates), $resumeAt];
                        });
                    } catch (Throwable $exception) {
                        return $this->failRun($run, $graph, $nodeId, $state, $exception);
                    }

                    if ($resumeAt instanceof DateTimeInterface) {
                        ContinueDelayedGraphJob::dispatch($run['public_id'], [
                            'interrupt_id' => $interrupt['interrupt_id'],
                        ])->delay($resumeAt);
                    }

                    $this->dispatchRunEvent('interrupt.created', new GraphInterrupted($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, $interrupt));

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
            }

            $run = $this->transaction(fn () => $this->runs->update($run['public_id'], ['status' => 'completed', 'current_checkpoint_id' => $checkpointId]));
            $this->dispatchRunEvent('run.completed', new GraphRunCompleted($run['public_id'], $run['thread_id'], $graph->key(), payload: ['state' => $state]));

            return new RunResult($run, $state);
        });
    }

    protected function invokeNode(GraphDefinition $graph, string $nodeId, array $state, array $run, ?string $checkpointId): NodeResult
    {
        $node = $graph->node($nodeId);
        $instance = is_string($node) ? $this->container->make($node) : $node;
        $context = new NodeContext(
            state: $state,
            runId: $run['public_id'],
            threadId: $run['thread_id'],
            nodeId: $nodeId,
            checkpointId: $checkpointId,
            graphMeta: ['key' => $graph->key(), 'version' => $graph->version()],
            memory: $this->memory,
            traces: $this->traces,
            tasks: new TaskRunner(app(TaskStore::class), $run['public_id'], $nodeId, $checkpointId),
        );

        if ($instance instanceof Node || is_callable($instance)) {
            $result = $instance($context);

            return is_array($result) ? NodeResult::write($result) : $result;
        }

        throw new RuntimeException("Node [{$nodeId}] is not invokable.");
    }

    protected function failRun(array $run, GraphDefinition $graph, string $nodeId, array $state, Throwable $exception): RunResult
    {
        $run = $this->transaction(fn () => $this->runs->update($run['public_id'], [
            'status' => 'failed',
            'error' => ['message' => $exception->getMessage()],
        ]));

        $this->traces->record($run['public_id'], 'node.failed', ['node' => $nodeId, 'message' => $exception->getMessage()]);
        $this->dispatchRunEvent('node.failed', new GraphNodeFailed($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, ['message' => $exception->getMessage()]));
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

    protected function transaction(callable $callback): mixed
    {
        return $this->container->make('db')->transaction($callback);
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
            ]),
        );
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

    protected function scheduler(): RuntimeScheduler
    {
        return new RuntimeScheduler;
    }
}
