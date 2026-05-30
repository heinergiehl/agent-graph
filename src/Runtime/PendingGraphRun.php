<?php

namespace Heiner\AgentGraph\Runtime;

use Closure;
use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Graph\GraphDefinition;
use InvalidArgumentException;

class PendingGraphRun
{
    protected ?string $threadId = null;

    protected array $input = [];

    protected array $meta = [];

    protected ?Closure $onEvent = null;

    protected bool $collectEvents = false;

    protected RuntimeOptions $options;

    public function __construct(
        protected AgentGraphManager $manager,
        protected GraphDefinition $graph,
    ) {
        $this->options = new RuntimeOptions;
    }

    public function thread(string $threadId): self
    {
        $this->threadId = $threadId;

        return $this;
    }

    public function input(array $input): self
    {
        $this->input = $input;

        return $this;
    }

    public function meta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    public function parent(string $runId, ?string $checkpointId = null, ?string $nodeId = null, int $depth = 1, string $relationship = 'child'): self
    {
        if (trim($runId) === '') {
            throw new InvalidArgumentException('Parent run id must not be empty.');
        }

        if ($depth < 1) {
            throw new InvalidArgumentException('Parent depth must be at least 1.');
        }

        if (trim($relationship) === '') {
            throw new InvalidArgumentException('Parent relationship must not be empty.');
        }

        $this->meta['parent'] = [
            'run_id' => $runId,
            'checkpoint_id' => $checkpointId,
            'node_id' => $nodeId,
            'depth' => $depth,
            'relationship' => $relationship,
        ];

        return $this;
    }

    public function onEvent(callable $listener): self
    {
        $this->onEvent = Closure::fromCallable($listener);

        return $this;
    }

    public function collectEvents(bool $collect = true): self
    {
        $this->collectEvents = $collect;

        return $this;
    }

    public function options(RuntimeOptions|array $options): self
    {
        $this->options = RuntimeOptions::from($options);

        return $this;
    }

    public function maxSteps(int $maxSteps): self
    {
        return $this->options(['max_steps' => $maxSteps]);
    }

    public function run(): RunResult
    {
        return $this->manager->run(
            $this->graph,
            $this->threadId ?? (string) str()->ulid(),
            $this->input,
            $this->meta,
            $this->onEvent,
            $this->collectEvents,
            $this->options,
        );
    }
}
