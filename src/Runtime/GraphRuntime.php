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
use Illuminate\Contracts\Container\Container;
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
    ) {}

    public function run(GraphDefinition $graph, string $threadId, array $input = [], array $meta = []): RunResult
    {
        $run = $this->runs->create($graph->key(), $graph->version(), $threadId, $input, $meta);
        event(new GraphRunStarted($run['public_id'], $threadId, $graph->key(), payload: ['input' => $input]));

        return $this->continue($graph, $run, $input, [$graph->entryNode()]);
    }

    /**
     * @param  array<string, GraphDefinition>  $graphs
     */
    public function resume(string $runId, array $payload, array $graphs): RunResult
    {
        $run = $this->runs->find($runId) ?? throw new RuntimeException("Run [{$runId}] was not found.");
        $graph = $graphs[$run['graph_key']] ?? throw new RuntimeException("Graph [{$run['graph_key']}] is not defined.");
        $checkpoint = $this->checkpoints->latestForRun($runId) ?? throw new RuntimeException("Run [{$runId}] has no checkpoint.");
        $interrupt = $this->interrupts->pendingForRun($runId);

        if ($interrupt !== null && isset($payload['interrupt_id'])) {
            $this->interrupts->resolve($payload['interrupt_id'], $payload);
        }

        unset($payload['interrupt_id']);

        $state = array_merge($checkpoint['state'], $payload);
        $next = $checkpoint['next_nodes'] ?: [$graph->entryNode()];
        $run = $this->runs->update($runId, ['status' => 'running']);
        event(new GraphResumed($runId, $run['thread_id'], $graph->key(), payload: $payload));

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
        event(new GraphRunCancelled($runId, $run['thread_id'], $run['graph_key'], payload: $meta));

        return new RunResult($run, $checkpoint['state'] ?? []);
    }

    protected function continue(GraphDefinition $graph, array $run, array $state, array $nextNodes): RunResult
    {
        return $this->locks->withLock('agent-graph:run:'.$run['public_id'], function () use ($graph, $run, $state, $nextNodes) {
            $maxSteps = (int) config('agent-graph.max_steps', 100);
            $step = (int) (($this->checkpoints->latestForRun($run['public_id'])['step'] ?? 0));
            $checkpointId = $this->checkpoints->latestForRun($run['public_id'])['checkpoint_id'] ?? null;
            $reducer = new StateReducer($this->inferReducers($graph));

            while ($nextNodes !== [] && ! in_array(StateGraph::END, $nextNodes, true)) {
                if ($step >= $maxSteps) {
                    $run = $this->runs->update($run['public_id'], ['status' => 'failed', 'error' => ['message' => 'Maximum graph steps exceeded.']]);
                    event(new GraphRunFailed($run['public_id'], $run['thread_id'], $graph->key(), payload: $run['error']));

                    return new RunResult($run, $state);
                }

                $nodeId = array_shift($nextNodes);
                event(new GraphNodeStarted($run['public_id'], $run['thread_id'], $graph->key(), $nodeId));

                try {
                    $result = $this->invokeNode($graph, $nodeId, $state, $run, $checkpointId);
                } catch (Throwable $exception) {
                    $run = $this->runs->update($run['public_id'], ['status' => 'failed', 'error' => ['message' => $exception->getMessage()]]);
                    $this->traces->record($run['public_id'], 'node.failed', ['node' => $nodeId, 'message' => $exception->getMessage()]);
                    event(new GraphNodeFailed($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, ['message' => $exception->getMessage()]));
                    event(new GraphRunFailed($run['public_id'], $run['thread_id'], $graph->key(), payload: $run['error']));

                    return new RunResult($run, $state);
                }

                if ($result->status() === 'failed') {
                    $run = $this->runs->update($run['public_id'], ['status' => 'failed', 'error' => ['message' => $result->failureMessage(), 'meta' => $result->meta()]]);
                    event(new GraphNodeFailed($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, $run['error']));
                    event(new GraphRunFailed($run['public_id'], $run['thread_id'], $graph->key(), payload: $run['error']));

                    return new RunResult($run, $state);
                }

                $state = $reducer->apply($state, $result->writes());
                $nextNodes = $this->nextNodesFor($graph, $nodeId, $result, $state);
                $step++;

                try {
                    $checkpoint = $this->transaction(fn () => tap($this->checkpoints->create([
                        'run_id' => $run['public_id'],
                        'thread_id' => $run['thread_id'],
                        'graph_key' => $graph->key(),
                        'graph_version' => $graph->version(),
                        'parent_checkpoint_id' => $checkpointId,
                        'step' => $step,
                        'state' => $state,
                        'next_nodes' => $result->status() === 'interrupted' ? [$nodeId] : $nextNodes,
                        'completed_nodes' => [$nodeId],
                        'interrupts' => [],
                        'meta' => $result->meta(),
                    ]), function (array $checkpoint) use ($run, $nodeId, $result): void {
                        $this->writes->createMany($run['public_id'], $checkpoint['checkpoint_id'], $nodeId, $result->writes(), $result->meta());
                        $this->traces->record($run['public_id'], 'checkpoint.created', ['checkpoint_id' => $checkpoint['checkpoint_id'], 'node' => $nodeId]);
                    }));
                } catch (Throwable $exception) {
                    return $this->failRun($run, $graph, $nodeId, $state, $exception);
                }

                $checkpointId = $checkpoint['checkpoint_id'];
                event(new GraphNodeCompleted($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, ['writes' => $result->writes()]));
                event(new GraphCheckpointCreated($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, ['checkpoint_id' => $checkpointId]));

                if ($result->status() === 'interrupted') {
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

                    event(new GraphInterrupted($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, $interrupt));

                    return new RunResult($run, $state, $interrupt);
                }

                if ($result->status() === 'completed' || in_array(StateGraph::END, $nextNodes, true) || $nextNodes === []) {
                    $run = $this->transaction(fn () => $this->runs->update($run['public_id'], [
                        'status' => 'completed',
                        'current_checkpoint_id' => $checkpointId,
                    ]));
                    event(new GraphRunCompleted($run['public_id'], $run['thread_id'], $graph->key(), payload: ['state' => $state]));

                    return new RunResult($run, $state);
                }
            }

            $run = $this->transaction(fn () => $this->runs->update($run['public_id'], ['status' => 'completed', 'current_checkpoint_id' => $checkpointId]));
            event(new GraphRunCompleted($run['public_id'], $run['thread_id'], $graph->key(), payload: ['state' => $state]));

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
        event(new GraphNodeFailed($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, ['message' => $exception->getMessage()]));
        event(new GraphRunFailed($run['public_id'], $run['thread_id'], $graph->key(), payload: $run['error']));

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
}
