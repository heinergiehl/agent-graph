<?php

namespace Heiner\AgentGraph\Runtime;

use Closure;
use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Graph\GraphDefinition;
use ReflectionFunction;
use RuntimeException;

class SubgraphNode implements Node
{
    protected string $mode = 'isolated';

    protected ?Closure $inputMapper = null;

    protected ?Closure $outputMapper = null;

    protected function __construct(
        protected string $id,
        protected string|GraphDefinition $graph,
    ) {}

    public static function make(string $id, string|GraphDefinition $graph): self
    {
        return new self($id, $graph);
    }

    public function graphDefinition(): ?GraphDefinition
    {
        return $this->graph instanceof GraphDefinition ? $this->graph : null;
    }

    public function isolated(?Closure $input = null, ?Closure $output = null): self
    {
        $this->mode = 'isolated';
        $this->inputMapper = $input;
        $this->outputMapper = $output;

        return $this;
    }

    public function shared(?Closure $input = null, ?Closure $output = null): self
    {
        $this->mode = 'shared';
        $this->inputMapper = $input;
        $this->outputMapper = $output;

        return $this;
    }

    public function mapped(?Closure $input = null, ?Closure $output = null): self
    {
        $this->mode = 'mapped';
        $this->inputMapper = $input;
        $this->outputMapper = $output;

        return $this;
    }

    public function __invoke(NodeContext $context): NodeResult
    {
        $manager = app(AgentGraphManager::class);

        if ($context->hasResumePayload()) {
            $payload = $context->resumePayload();
            $childRunId = $payload['child_run_id'] ?? null;
            $childInterruptId = $payload['child_interrupt_id'] ?? null;

            if (! is_string($childRunId) || $childRunId === ''
                || ! is_string($childInterruptId) || $childInterruptId === '') {
                throw new RuntimeException("Subgraph node [{$this->id}] resume requires child_run_id and child_interrupt_id.");
            }

            $snapshot = $manager->inspect($childRunId)
                ?? throw new RuntimeException("Subgraph child run [{$childRunId}] was not found.");
            $parent = $snapshot->parent();

            if (($parent['relationship'] ?? null) !== 'subgraph'
                || ($parent['run_id'] ?? null) !== $context->runId()
                || ($parent['node_id'] ?? null) !== $context->nodeId()) {
                throw new RuntimeException("Subgraph child run [{$childRunId}] is not bound to the current parent node.");
            }

            if (RunStatus::isTerminal($snapshot->status())) {
                return $this->resultFromChild($snapshot->toRunResult(), $context);
            }

            unset($payload['child_run_id'], $payload['child_interrupt_id']);
            $payload['interrupt_id'] = $childInterruptId;
            $payload = $this->withNestedChildIdentity($snapshot, $childInterruptId, $payload);
            $child = $manager->resume($childRunId, $payload);

            return $this->resultFromChild($child, $context);
        }

        $pending = $this->pendingRun($manager, $context);
        $child = $pending->run();

        return $this->resultFromChild($child, $context);
    }

    protected function pendingRun(AgentGraphManager $manager, NodeContext $context): PendingGraphRun
    {
        $threadId = $this->mode === 'shared'
            ? $context->threadId()
            : $context->threadId().':'.$context->runId().':'.$context->nodeId();
        $input = $this->resolveInput($context);
        $parentDepth = max(1, (int) data_get($context->graphMeta(), 'parent.depth', 0) + 1);

        $pending = is_string($this->graph)
            ? $manager->graph($this->graph)
            : new PendingGraphRun($manager, $this->graph);

        return $pending
            ->thread($threadId)
            ->input($input)
            ->parent($context->runId(), $context->checkpointId(), $context->nodeId(), $parentDepth, 'subgraph');
    }

    protected function resultFromChild(RunResult $child, NodeContext $context): NodeResult
    {
        if (in_array($child->status(), [RunStatus::INTERRUPTED, RunStatus::DELAYED], true)) {
            $interrupt = $child->interrupt();

            if (! is_string($interrupt['interrupt_id'] ?? null) || $interrupt['interrupt_id'] === '') {
                return NodeResult::fail('Subgraph child run ['.$child->runId().'] is waiting without an interrupt identity.');
            }

            return NodeResult::interrupt('subgraph', [
                'child_run_id' => $child->runId(),
                'child_interrupt_id' => $interrupt['interrupt_id'],
                'child_status' => $child->status(),
                'child_interrupt' => $interrupt,
            ]);
        }

        if ($child->failed()) {
            return NodeResult::fail('Subgraph child run ['.$child->runId().'] failed.', [
                'child_run_id' => $child->runId(),
                'error' => $child->error(),
            ]);
        }

        if (! $child->completed()) {
            return NodeResult::fail('Subgraph child run ['.$child->runId().'] did not complete: '.$child->status().'.', [
                'child_run_id' => $child->runId(),
                'child_status' => $child->status(),
            ]);
        }

        return NodeResult::write($this->resolveOutput($child->state(), $context, $child));
    }

    protected function withNestedChildIdentity(RunSnapshot $child, string $interruptId, array $payload): array
    {
        $interrupt = $child->interrupt();
        $binding = ($interrupt['type'] ?? null) === 'subgraph'
            ? ($interrupt['payload'] ?? [])
            : [];
        $acceptedPayload = null;

        if ($interrupt === null) {
            $pending = data_get($child->meta(), 'runtime.recovery.pending_resume');

            if (is_array($pending) && ($pending['interrupt_id'] ?? null) === $interruptId) {
                $binding = $pending['resume_payload'] ?? [];
                $acceptedPayload = is_array($binding) ? $binding : null;
            }
        }

        foreach (['child_run_id', 'child_interrupt_id'] as $key) {
            if (is_string($binding[$key] ?? null)) {
                $payload[$key] = $binding[$key];
            }
        }

        if ($acceptedPayload !== null) {
            // Existing resume receipts hash field order; preserve it without filling in or replacing caller answers.
            $payload = array_replace(array_intersect_key($acceptedPayload, $payload), $payload);
        }

        return $payload;
    }

    protected function resolveInput(NodeContext $context): array
    {
        if ($this->inputMapper === null) {
            return $context->state();
        }

        return $this->invokeMapper($this->inputMapper, [$context->state(), $context]);
    }

    protected function resolveOutput(array $childState, NodeContext $context, RunResult $child): array
    {
        if ($this->outputMapper === null) {
            return $this->mode === 'isolated' ? [] : $childState;
        }

        return $this->invokeMapper($this->outputMapper, [$childState, $context, $child]);
    }

    protected function invokeMapper(Closure $mapper, array $arguments): array
    {
        $reflection = new ReflectionFunction($mapper);
        $value = $mapper(...array_slice($arguments, 0, $reflection->getNumberOfParameters()));

        if (! is_array($value)) {
            throw new RuntimeException("Subgraph node [{$this->id}] mapper must return an array.");
        }

        return $value;
    }
}
