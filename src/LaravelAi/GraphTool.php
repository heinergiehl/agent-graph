<?php

namespace Heiner\AgentGraph\LaravelAi;

use Closure;
use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Graph\GraphSchemaExporter;
use Heiner\AgentGraph\Runtime\RunResult;
use Heiner\AgentGraph\Runtime\RuntimeError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
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

    protected ?Closure $schemaInputResolver = null;

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

    public function schemaInput(Closure $resolver): self
    {
        $this->schemaInputResolver = $resolver;

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
            'input' => $this->inputSchema($schema)
                ->description('Structured graph input or interrupt response payload.'),
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
            throw new InvalidArgumentException('GraphTool meta hook must return an array.');
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

    protected function inputSchema(JsonSchema $schema): Type
    {
        if ($this->schemaInputResolver !== null) {
            $resolved = ($this->schemaInputResolver)($schema);

            if (! $resolved instanceof Type) {
                throw new InvalidArgumentException('GraphTool schemaInput hook must return an Illuminate JsonSchema type.');
            }

            return $resolved;
        }

        try {
            $definition = $this->manager->definition($this->graphKey);
        } catch (InvalidArgumentException) {
            return $schema->object()->nullable();
        }

        $properties = [];

        foreach ((new GraphSchemaExporter)->state($definition->schema()) as $channel => $state) {
            $properties[$channel] = $this->jsonSchemaType($schema, $state);
        }

        return $schema->object($properties);
    }

    protected function jsonSchemaType(JsonSchema $schema, array $state): Type
    {
        $nullable = (bool) ($state['nullable'] ?? false);
        $stateType = $state['type'] ?? 'mixed';

        if (is_array($stateType)) {
            return $this->unionSchemaType($schema, $stateType, $nullable);
        }

        $type = match ($stateType) {
            'string' => $schema->string(),
            'int', 'integer' => $schema->integer(),
            'float', 'double', 'number' => $schema->number(),
            'bool', 'boolean' => $schema->boolean(),
            'array', 'messages' => $this->arraySchemaType($schema, $state),
            'object' => $this->objectSchemaType($schema, $state),
            'enum' => $schema->string()->enum((array) ($state['values'] ?? [])),
            default => $schema->object(),
        };

        return $nullable ? $type->nullable() : $type;
    }

    protected function unionSchemaType(JsonSchema $schema, array $stateTypes, bool $nullable): Type
    {
        $types = array_values(array_unique(array_map(
            fn (mixed $type): string => (string) $type,
            $stateTypes,
        )));

        if (count($types) === 1) {
            return $this->jsonSchemaType($schema, [
                'type' => $types[0],
                'nullable' => $nullable,
            ]);
        }

        $displayTypes = $types;

        if ($nullable) {
            $displayTypes[] = 'null';
        }

        $description = 'Accepts one of: '.implode(', ', $displayTypes).'. Use schemaInput() for a tighter public tool contract.';

        return $this->unionFallbackSchemaType($schema, $types, $nullable)
            ->description($description);
    }

    protected function unionFallbackSchemaType(JsonSchema $schema, array $types, bool $nullable): Type
    {
        $normalized = array_map(fn (string $type): string => match ($type) {
            'int' => 'integer',
            'bool' => 'boolean',
            'float', 'double' => 'number',
            default => $type,
        }, $types);

        $allScalars = collect($normalized)
            ->every(fn (string $type): bool => in_array($type, ['string', 'integer', 'number', 'boolean'], true));

        if ($allScalars) {
            $type = $schema->string();

            return $nullable ? $type->nullable() : $type;
        }

        if (in_array('array', $normalized, true) && ! in_array('object', $normalized, true)) {
            $type = $schema->array();

            return $nullable ? $type->nullable() : $type;
        }

        $type = $schema->object();

        return $nullable ? $type->nullable() : $type;
    }

    protected function arraySchemaType(JsonSchema $schema, array $state): Type
    {
        $array = $schema->array();

        if (isset($state['items']) && is_array($state['items'])) {
            $array->items($this->jsonSchemaType($schema, $state['items']));
        }

        return $array;
    }

    protected function objectSchemaType(JsonSchema $schema, array $state): Type
    {
        $properties = [];

        foreach ((array) ($state['properties'] ?? []) as $property => $definition) {
            if (is_array($definition)) {
                $properties[$property] = $this->jsonSchemaType($schema, $definition);
            }
        }

        return $schema->object($properties);
    }
}
