<?php

namespace Heiner\AgentGraph\LaravelAi;

use Closure;
use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Runtime\RunResult;
use Heiner\AgentGraph\Runtime\RuntimeError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class GraphTool implements Stringable, Tool
{
    protected string $name;

    protected Stringable|string $description;

    protected ?Closure $threadResolver = null;

    protected ?Closure $inputMapper = null;

    protected ?Closure $outputMapper = null;

    protected Closure|array|null $metaResolver = null;

    public function __construct(
        protected AgentGraphManager $manager,
        protected string $graphKey,
    ) {
        $this->name = ToolName::fromGraphKey('run', $graphKey);
        $this->description = "Run the {$graphKey} agent graph.";
    }

    public function name(?string $name = null): string|self
    {
        if ($name === null) {
            return $this->name;
        }

        $this->name = ToolName::assertValid($name);

        return $this;
    }

    public function description(Stringable|string|null $description = null): Stringable|string
    {
        if ($description === null) {
            return $this->description;
        }

        $this->description = $description;

        return $this;
    }

    public function thread(Closure|string $thread): self
    {
        $this->threadResolver = $thread instanceof Closure
            ? $thread
            : fn () => $thread;

        return $this;
    }

    public function input(Closure $mapper): self
    {
        $this->inputMapper = $mapper;

        return $this;
    }

    public function output(Closure $mapper): self
    {
        $this->outputMapper = $mapper;

        return $this;
    }

    public function meta(Closure|array $meta): self
    {
        $this->metaResolver = $meta;

        return $this;
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $input = $this->resolveInput($request);

            if (isset($request['run_id'])) {
                $payload = is_array($input) ? $input : ['input' => $input];

                if (isset($request['interrupt_id'])) {
                    $payload['interrupt_id'] = $request['interrupt_id'];
                }

                $run = $this->manager->resume($request['run_id'], $payload);
            } else {
                $threadId = $this->resolveThread($request);
                $pending = $this->manager->graph($this->graphKey)
                    ->thread($threadId)
                    ->input(is_array($input) ? $input : ['input' => $input]);

                $meta = $this->resolveMeta($request);

                if ($meta !== []) {
                    $pending->meta($meta);
                }

                $run = $pending->run();
            }

            return $this->encodeResponse($this->resolveOutput($run, $request));
        } catch (Throwable $exception) {
            return $this->encodeResponse([
                'status' => 'failed',
                'run_id' => $request['run_id'] ?? null,
                'thread_id' => $request['thread_id'] ?? null,
                'state' => [],
                'interrupt' => null,
                'error' => RuntimeError::fromThrowable($exception),
            ]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread_id' => $schema->string()
                ->description('Existing or new AgentGraph thread identifier. Omit to let the tool create one.')
                ->nullable(),
            'run_id' => $schema->string()
                ->description('Existing AgentGraph run identifier when resuming an interrupted graph.')
                ->nullable(),
            'interrupt_id' => $schema->string()
                ->description('Pending interrupt identifier being answered during resume.')
                ->nullable(),
            'input' => $schema->object()
                ->description('Structured graph input or interrupt response payload.')
                ->nullable(),
        ];
    }

    public function __toString(): string
    {
        return (string) $this->description;
    }

    protected function resolveThread(Request $request): string
    {
        if ($this->threadResolver !== null) {
            return (string) ($this->threadResolver)($request);
        }

        return (string) ($request['thread_id'] ?? str()->ulid());
    }

    protected function resolveInput(Request $request): mixed
    {
        if ($this->inputMapper !== null) {
            return ($this->inputMapper)($request);
        }

        return $request['input'] ?? collect($request->all())
            ->except(['thread_id', 'run_id', 'interrupt_id'])
            ->all();
    }

    protected function resolveMeta(Request $request): array
    {
        if ($this->metaResolver === null) {
            return [];
        }

        $meta = $this->metaResolver instanceof Closure
            ? ($this->metaResolver)($request)
            : $this->metaResolver;

        if (! is_array($meta)) {
            throw new \InvalidArgumentException('GraphTool meta hook must return an array.');
        }

        return $meta;
    }

    protected function resolveOutput(RunResult $run, Request $request): mixed
    {
        if ($this->outputMapper !== null) {
            return ($this->outputMapper)($run, $request);
        }

        return [
            'status' => $run->status(),
            'run_id' => $run->runId(),
            'thread_id' => $run->threadId(),
            'state' => $run->state(),
            'interrupt' => $run->interrupt(),
            'error' => $run->error(),
        ];
    }

    protected function encodeResponse(mixed $payload): string
    {
        if ($payload instanceof Stringable || is_string($payload)) {
            return (string) $payload;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
