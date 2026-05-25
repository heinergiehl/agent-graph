<?php

namespace Heiner\AgentGraph\LaravelAi;

use Closure;
use Heiner\AgentGraph\AgentGraphManager;
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

    public function __construct(
        protected AgentGraphManager $manager,
        protected string $graphKey,
    ) {
        $this->name = 'run_'.$graphKey;
        $this->description = "Run the {$graphKey} agent graph.";
    }

    public function name(?string $name = null): string|self
    {
        if ($name === null) {
            return $this->name;
        }

        $this->name = $name;

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

    public function handle(Request $request): Stringable|string
    {
        try {
            $input = $request['input'] ?? collect($request->all())
                ->except(['thread_id', 'run_id', 'interrupt_id'])
                ->all();

            if (isset($request['run_id'])) {
                $payload = is_array($input) ? $input : ['input' => $input];

                if (isset($request['interrupt_id'])) {
                    $payload['interrupt_id'] = $request['interrupt_id'];
                }

                $run = $this->manager->resume($request['run_id'], $payload);
            } else {
                $threadId = $this->resolveThread($request);
                $run = $this->manager->graph($this->graphKey)
                    ->thread($threadId)
                    ->input(is_array($input) ? $input : ['input' => $input])
                    ->run();
            }

            return $this->encodeResponse([
                'status' => $run->status(),
                'run_id' => $run->runId(),
                'thread_id' => $run->threadId(),
                'state' => $run->state(),
                'interrupt' => $run->interrupt(),
                'error' => $run->error(),
            ]);
        } catch (Throwable $exception) {
            return $this->encodeResponse([
                'status' => 'failed',
                'run_id' => $request['run_id'] ?? null,
                'thread_id' => $request['thread_id'] ?? null,
                'state' => [],
                'interrupt' => null,
                'error' => [
                    'message' => $exception->getMessage(),
                    'type' => $exception::class,
                ],
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

    /**
     * @param  array{status:string,run_id:?string,thread_id:?string,state:array,interrupt:?array,error:?array}  $payload
     */
    protected function encodeResponse(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
