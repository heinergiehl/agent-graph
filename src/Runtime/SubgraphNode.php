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

        if ($context->hasResumePayload() && is_string($context->resumePayload()['child_run_id'] ?? null)) {
            $payload = $context->resumePayload();
            $childInterruptId = $payload['child_interrupt_id'] ?? null;

            if (! is_string($childInterruptId) || $childInterruptId === '') {
                throw new RuntimeException("Subgraph node [{$this->id}] resume requires child_interrupt_id.");
            }

            unset($payload['child_run_id'], $payload['child_interrupt_id']);
            $payload['interrupt_id'] = $childInterruptId;
            $child = $manager->resume((string) $context->resumePayload()['child_run_id'], $payload);

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
        if ($child->interrupted()) {
            $interrupt = $child->interrupt();

            return NodeResult::interrupt('subgraph', [
                'child_run_id' => $child->runId(),
                'child_interrupt_id' => $interrupt['interrupt_id'] ?? null,
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

        return NodeResult::write($this->resolveOutput($child->state(), $context, $child));
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
