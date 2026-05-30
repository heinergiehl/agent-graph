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

class DurableGraphTool implements Stringable, Tool
{
    protected string $name;

    protected Stringable|string $description;

    protected ?Closure $threadResolver = null;

    protected bool $strictResume = false;

    public function __construct(
        protected AgentGraphManager $manager,
        protected string $graphKey,
    ) {
        $this->name = ToolName::fromGraphKey('durable', $graphKey);
        $this->description = "Run or resume the active {$graphKey} agent graph session.";
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
        $this->threadResolver = $thread instanceof Closure ? $thread : fn () => $thread;

        return $this;
    }

    public function strictResume(bool $strict = true): self
    {
        $this->strictResume = $strict;

        return $this;
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $threadId = $this->resolveThread($request);
            $session = $this->manager->session($this->graphKey, $threadId);
            $action = $request['action'] ?? 'auto';

            if ($action === 'status') {
                return $this->encodeResponse($session->status());
            }

            if ($action === 'cancel') {
                return $this->encodeResponse($this->response($session->cancel(['tool' => $this->name])));
            }

            $input = is_array($request['input'] ?? null) ? $request['input'] : [];

            if (isset($request['run_id'])) {
                $input['run_id'] = $request['run_id'];
            }

            if (isset($request['interrupt_id'])) {
                $input['interrupt_id'] = $request['interrupt_id'];
            }

            if (isset($input['interrupt_id']) || isset($input['run_id'])) {
                return $this->encodeResponse($this->response($session->resume($input, $this->strictResume)));
            }

            return $this->encodeResponse($this->response($session->run($input)));
        } catch (Throwable $exception) {
            return $this->encodeResponse([
                'status' => 'failed',
                'run_id' => $request['run_id'] ?? null,
                'thread_id' => $request['thread_id'] ?? null,
                'state' => [],
                'interrupt' => null,
                'summary' => null,
                'error' => RuntimeError::fromThrowable($exception),
            ]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread_id' => $schema->string()->description('Durable AgentGraph thread identifier.')->nullable(),
            'action' => $schema->string()->description('auto, status, or cancel.')->nullable(),
            'run_id' => $schema->string()->description('Run identifier to resume.')->nullable(),
            'interrupt_id' => $schema->string()->description('Pending interrupt identifier to answer.')->nullable(),
            'input' => $schema->object()->description('Graph input or interrupt response payload.')->nullable(),
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

    protected function response(RunResult $run): array
    {
        return [
            'status' => $run->status(),
            'run_id' => $run->runId(),
            'thread_id' => $run->threadId(),
            'state' => $run->state(),
            'interrupt' => $run->interrupt(),
            'summary' => [
                'status' => $run->status(),
                'interrupted' => $run->interrupted(),
                'completed' => $run->completed(),
            ],
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
