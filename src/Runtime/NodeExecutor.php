<?php

namespace Heiner\AgentGraph\Runtime;

use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Contracts\MemoryStore;
use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Contracts\TaskStore;
use Heiner\AgentGraph\Contracts\TraceStore;
use Heiner\AgentGraph\Events\GraphNodeRetrying;
use Heiner\AgentGraph\Exceptions\AgentApprovalRequiredException;
use Heiner\AgentGraph\Exceptions\NodeExecutionClaimLostException;
use Heiner\AgentGraph\Exceptions\NodeTimeoutException;
use Heiner\AgentGraph\Exceptions\RunStateChangedException;
use Heiner\AgentGraph\Graph\GraphDefinition;
use Heiner\AgentGraph\Graph\RetryPolicy;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use TypeError;

/** @internal Invokes one node; receipt persistence, routing and checkpoint commits belong to the runtime. */
class NodeExecutor
{
    public function __construct(
        protected Container $container,
        protected MemoryStore $memory,
        protected TraceStore $traces,
        protected TaskStore $tasks,
        protected LockProvider $locks,
        protected RunEventDispatcher $events,
    ) {}

    public function execute(
        GraphDefinition $graph,
        string $nodeId,
        array $state,
        array $run,
        ExecutionAuthority $authority,
        ?string $checkpointId = null,
        ?array $resumePayload = null,
        ?string $interruptId = null,
    ): NodeResult {
        if ($authority->runId() !== $run['public_id'] || $authority->revision() !== (int) $run['revision']) {
            throw new InvalidArgumentException('Node context and execution authority must refer to the same run revision.');
        }
        $node = $graph->node($nodeId);
        $instance = is_string($node) ? $this->container->make($node) : $node;
        if (! $instance instanceof Node && ! is_callable($instance)) {
            throw new RuntimeException("Node [{$nodeId}] is not invokable.");
        }

        $policy = $graph->nodePolicy($nodeId);
        $authority = $authority->withTimeout($policy->timeoutPolicy()?->seconds());
        $retryPolicy = $policy->retryPolicy();
        $invoke = function () use ($instance, $graph, $nodeId, $state, $run, $authority, $checkpointId, $resumePayload, $interruptId, $retryPolicy): NodeResult {
            $attempt = 0;
            $failedAttempts = 0;

            while (true) {
                $attempt++;
                $context = $this->nodeContext($graph, $nodeId, $state, $run, $authority, $checkpointId, $resumePayload, $interruptId);
                try {
                    $result = $this->callNode($instance, $context);

                    return $retryPolicy === null ? $result : $this->withRetryMeta($result, $retryPolicy, $attempt, $failedAttempts);
                } catch (Throwable $exception) {
                    $failedAttempts++;
                    if ($retryPolicy === null
                        || $exception instanceof RunStateChangedException
                        || $exception instanceof NodeTimeoutException
                        || $exception instanceof NodeExecutionClaimLostException
                        || $exception instanceof AgentApprovalRequiredException
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
                    $this->events->notify(fn () => $this->traces->record($run['public_id'], 'node.retrying', $payload));
                    $this->events->dispatch('node.retrying', new GraphNodeRetrying($run['public_id'], $run['thread_id'], $graph->key(), $nodeId, $payload));

                    if ($delayMs > 0) {
                        $remaining = $authority->remainingSeconds();
                        $sleepMs = $remaining === null ? $delayMs : min($delayMs, $remaining * 1000);
                        usleep((int) ($sleepMs * 1000));
                    }
                }
            }
        };

        $concurrency = $policy->concurrencyPolicy();
        if ($concurrency !== null && $concurrency->limit() === 1) {
            return $this->locks->withLock($concurrency->key() ?? 'agent-graph:node:'.$graph->key().':'.$nodeId, $invoke);
        }

        return $invoke();
    }

    protected function nodeContext(GraphDefinition $graph, string $nodeId, array $state, array $run, ExecutionAuthority $authority, ?string $checkpointId, ?array $resumePayload, ?string $interruptId): NodeContext
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
            tasks: new TaskRunner($this->tasks, $run['public_id'], $nodeId, $checkpointId, $authority->assertActive(...)),
            resumePayload: $resumePayload,
            interruptId: $interruptId,
            executionGuard: $authority->assertCurrent(...),
            deadline: $authority->deadline(),
        );
    }

    protected function callNode(mixed $instance, NodeContext $context): NodeResult
    {
        $context->assertActive();
        $result = $instance($context);
        $context->assertActive();
        if (is_array($result)) {
            return NodeResult::write($result);
        }
        if (! $result instanceof NodeResult) {
            throw new TypeError("Node [{$context->nodeId()}] must return a NodeResult or an array state patch.");
        }

        return $result;
    }

    protected function withRetryMeta(NodeResult $result, RetryPolicy $policy, int $attempts, int $failedAttempts): NodeResult
    {
        return $result->withMeta(array_replace_recursive($result->meta(), [
            'runtime' => ['retry' => [
                'attempts' => $attempts,
                'max_attempts' => $policy->maxAttempts(),
                'failed_attempts' => $failedAttempts,
            ]],
        ]));
    }
}
