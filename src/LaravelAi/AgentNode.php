<?php

namespace Heiner\AgentGraph\LaravelAi;

use Closure;
use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Events\GraphStreamDelta;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunEventDispatcher;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use ReflectionFunction;
use RuntimeException;

class AgentNode implements Node
{
    protected Agent|string|null $agent = null;

    protected Closure|string|null $prompt = null;

    protected Closure|array $attachments = [];

    protected bool $stream = false;

    protected mixed $provider = null;

    protected ?string $model = null;

    protected ?int $timeout = null;

    protected ?string $textChannel = null;

    protected ?string $usageChannel = null;

    protected ?string $metaChannel = null;

    protected ?Closure $textDeltaCallback = null;

    protected ?string $structuredChannel = null;

    protected ?string $toolCallsChannel = null;

    protected ?string $toolResultsChannel = null;

    protected ?string $stepsChannel = null;

    protected ?string $streamEventsChannel = null;

    protected function __construct(protected string $id) {}

    public static function make(string $id): self
    {
        return new self($id);
    }

    public function agent(Agent|string $agent): self
    {
        $this->agent = $agent;

        return $this;
    }

    public function prompt(Closure|string $prompt): self
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function attachments(Closure|array $attachments): self
    {
        $this->attachments = $attachments;

        return $this;
    }

    public function stream(bool $stream = true): self
    {
        $this->stream = $stream;

        return $this;
    }

    public function provider(mixed $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function model(?string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function timeout(?int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function writeTextTo(string $channel): self
    {
        $this->textChannel = $channel;

        return $this;
    }

    public function writeUsageTo(string $channel): self
    {
        $this->usageChannel = $channel;

        return $this;
    }

    public function writeMetaTo(string $channel): self
    {
        $this->metaChannel = $channel;

        return $this;
    }

    public function onTextDelta(Closure $callback): self
    {
        $this->textDeltaCallback = $callback;

        return $this;
    }

    public function writeStructuredTo(string $channel): self
    {
        $this->structuredChannel = $channel;

        return $this;
    }

    public function writeToolCallsTo(string $channel): self
    {
        $this->toolCallsChannel = $channel;

        return $this;
    }

    public function writeToolResultsTo(string $channel): self
    {
        $this->toolResultsChannel = $channel;

        return $this;
    }

    public function writeStepsTo(string $channel): self
    {
        $this->stepsChannel = $channel;

        return $this;
    }

    public function writeStreamEventsTo(string $channel): self
    {
        $this->streamEventsChannel = $channel;

        return $this;
    }

    public function __invoke(NodeContext $context): NodeResult
    {
        $agent = $this->resolveAgent();
        $prompt = $this->resolveValue($this->prompt, $context);
        $attachments = $this->resolveValue($this->attachments, $context);

        if (! is_string($prompt)) {
            throw new RuntimeException("Agent node [{$this->id}] prompt must resolve to a string.");
        }

        $writes = [];
        $meta = ['agent_node' => $this->id];

        if ($this->stream) {
            $response = $agent->stream($prompt, (array) $attachments, $this->provider, $this->model, $this->timeout);
            $text = '';
            $streamEvents = [];
            $toolCalls = [];
            $toolResults = [];

            foreach ($response as $event) {
                if (method_exists($event, 'toArray')) {
                    $streamEvents[] = $event->toArray();
                }

                if ($event instanceof TextDelta) {
                    $text .= $event->delta;
                    $payload = [
                        'agent_node' => $this->id,
                        'invocation_id' => $event->invocationId,
                        'message_id' => $event->messageId,
                        'delta_id' => $event->id,
                        'delta' => $event->delta,
                        'timestamp' => $event->timestamp,
                    ];

                    app(RunEventDispatcher::class)->dispatch('stream.delta', new GraphStreamDelta(
                        runId: $context->runId(),
                        threadId: $context->threadId(),
                        graphKey: (string) ($context->graphMeta()['key'] ?? ''),
                        nodeId: $context->nodeId(),
                        payload: $payload,
                    ));

                    $context->traces()->record($context->runId(), 'stream.delta', $payload);
                    $this->invokeTextDeltaCallback($event, $payload, $context);
                } elseif ($event instanceof ToolCall) {
                    $toolCalls[] = $event->toolCall->toArray();
                } elseif ($event instanceof ToolResult) {
                    $toolResults[] = $event->toolResult->toArray();
                }
            }

            $usage = $response->usage;
            $responseMeta = null;
            $structured = null;
            $steps = [];
        } else {
            $response = $agent->prompt($prompt, (array) $attachments, $this->provider, $this->model, $this->timeout);
            $text = $response->text;
            $usage = $response->usage;
            $responseMeta = $response->meta;
            $structured = property_exists($response, 'structured') ? $response->structured : null;
            $toolCalls = $this->collectionToArray($response->toolCalls ?? []);
            $toolResults = $this->collectionToArray($response->toolResults ?? []);
            $steps = $this->collectionToArray($response->steps ?? []);
            $streamEvents = [];
        }

        if ($this->textChannel !== null) {
            $writes[$this->textChannel] = $text;
        }

        if ($this->usageChannel !== null && $usage !== null) {
            $writes[$this->usageChannel] = $usage->toArray();
        }

        if ($this->metaChannel !== null && $responseMeta !== null) {
            $writes[$this->metaChannel] = $responseMeta->toArray();
        }

        if ($this->structuredChannel !== null && $structured !== null) {
            $writes[$this->structuredChannel] = $structured;
        }

        if ($this->toolCallsChannel !== null) {
            $writes[$this->toolCallsChannel] = $toolCalls;
        }

        if ($this->toolResultsChannel !== null) {
            $writes[$this->toolResultsChannel] = $toolResults;
        }

        if ($this->stepsChannel !== null) {
            $writes[$this->stepsChannel] = $steps;
        }

        if ($this->streamEventsChannel !== null) {
            $writes[$this->streamEventsChannel] = $streamEvents;
        }

        return NodeResult::write($writes)->withMeta($meta);
    }

    protected function resolveAgent(): Agent
    {
        $agent = is_string($this->agent) ? app($this->agent) : $this->agent;

        if (! $agent instanceof Agent) {
            throw new RuntimeException("Agent node [{$this->id}] must be configured with a Laravel AI agent.");
        }

        return $agent;
    }

    protected function resolveValue(mixed $value, NodeContext $context): mixed
    {
        if (! $value instanceof Closure) {
            return $value;
        }

        $reflection = new ReflectionFunction($value);

        return $reflection->getNumberOfParameters() >= 2
            ? $value($context->state(), $context)
            : $value($context->state());
    }

    protected function invokeTextDeltaCallback(TextDelta $event, array $payload, NodeContext $context): void
    {
        if ($this->textDeltaCallback === null) {
            return;
        }

        $arguments = [
            $event->delta,
            $payload,
            $context,
            $event,
        ];
        $reflection = new ReflectionFunction($this->textDeltaCallback);

        ($this->textDeltaCallback)(...array_slice($arguments, 0, $reflection->getNumberOfParameters()));
    }

    protected function collectionToArray(mixed $items): array
    {
        if ($items instanceof Collection) {
            $items = $items->all();
        }

        return array_map(function (mixed $item): mixed {
            if (is_object($item) && method_exists($item, 'toArray')) {
                return $item->toArray();
            }

            if (is_object($item)) {
                return get_object_vars($item);
            }

            return $item;
        }, is_array($items) ? $items : []);
    }
}
