<?php

namespace Heiner\AgentGraph\Runtime;

use Closure;
use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Graph\GraphDefinition;

class PendingGraphRun
{
    protected ?string $threadId = null;

    protected array $input = [];

    protected array $meta = [];

    protected ?Closure $onEvent = null;

    protected bool $collectEvents = false;

    public function __construct(
        protected AgentGraphManager $manager,
        protected GraphDefinition $graph,
    ) {}

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

    public function run(): RunResult
    {
        return $this->manager->run(
            $this->graph,
            $this->threadId ?? (string) str()->ulid(),
            $this->input,
            $this->meta,
            $this->onEvent,
            $this->collectEvents,
        );
    }
}
