<?php

namespace Heiner\AgentGraph\LaravelAi;

use Closure;
use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Events\GraphStreamDelta;
use Heiner\AgentGraph\Exceptions\AgentApprovalRequiredException;
use Heiner\AgentGraph\Exceptions\AgentStreamException;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunEventDispatcher;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\Error as StreamError;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
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
        $context->assertActive();
        $agent = $this->resolveAgent();
        $prompt = $this->resolveValue($this->prompt, $context);
        $attachments = $this->resolveValue($this->attachments, $context);

        if (! is_string($prompt)) {
            throw new RuntimeException("Agent node [{$this->id}] prompt must resolve to a string.");
        }

        $context->assertActive();
        $remaining = $context->remainingSeconds();
        // Laravel AI accepts whole seconds; the node's monotonic deadline still
        // governs result admission and subsequent work at subsecond precision.
        $timeout = $remaining === null ? $this->timeout : min($this->timeout ?? PHP_INT_MAX, max(1, (int) ceil($remaining)));

        $writes = [];
        $meta = ['agent_node' => $this->id];

        if ($this->stream) {
            $this->assertSupportedStreamingMappings();

            $response = $agent->stream($prompt, (array) $attachments, $this->provider, $this->model, $timeout);
            $streamedResponse = null;
            $eventCount = 0;
            $textDeltaCount = 0;
            $dispatcher = app(RunEventDispatcher::class);

            $response->then(function (StreamedAgentResponse $completed) use (&$streamedResponse): void {
                $streamedResponse = $completed;
            });

            $streamEvents = $this->streamEventsChannel === null ? null : [];
            $nextAuthorityCheck = 0.0;

            foreach ($response as $event) {
                // Text is provisional: poll ownership at most every 50 ms during
                // a burst. Tool/control events and expired deadlines check now.
                $time = hrtime(true) / 1e9;
                if (! $event instanceof TextDelta || $time >= $nextAuthorityCheck || $context->remainingSeconds() === 0.0) {
                    $context->assertActive();
                    $nextAuthorityCheck = $time + 0.05;
                }
                $eventCount++;

                if ($event instanceof StreamError && ! $event->recoverable) {
                    throw new AgentStreamException($this->id, $event);
                }

                if ($event instanceof ToolApprovalRequest && $event->pendingApprovals->isNotEmpty()) {
                    throw new AgentApprovalRequiredException($this->id);
                }

                if ($streamEvents !== null && method_exists($event, 'toArray')) {
                    $streamEvents[] = $event->toArray();
                }

                if ($event instanceof TextDelta) {
                    $textDeltaCount++;
                    $payload = [
                        'agent_node' => $this->id,
                        'invocation_id' => $event->invocationId,
                        'message_id' => $event->messageId,
                        'delta_id' => $event->id,
                        'delta' => $event->delta,
                        'timestamp' => $event->timestamp,
                    ];

                    $dispatcher->dispatch('stream.delta', new GraphStreamDelta(
                        runId: $context->runId(),
                        threadId: $context->threadId(),
                        graphKey: (string) ($context->graphMeta()['key'] ?? ''),
                        nodeId: $context->nodeId(),
                        payload: $payload,
                    ));

                    $dispatcher->notify(fn () => $this->invokeTextDeltaCallback($event, $payload, $context));
                }
            }

            if (! $streamedResponse instanceof StreamedAgentResponse) {
                throw new RuntimeException("Agent node [{$this->id}] stream did not produce a completed Laravel AI response.");
            }

            $text = $streamedResponse->text;
            $usage = $streamedResponse->usage;
            $responseMeta = $streamedResponse->meta;
            $structured = null;
            $toolCalls = $this->collectionToArray($streamedResponse->toolCalls);
            $toolResults = $this->collectionToArray($streamedResponse->toolResults);
            $steps = [];

            $dispatcher->notify(fn () => $context->traces()->record($context->runId(), 'stream.completed', [
                'agent_node' => $this->id,
                'invocation_id' => $streamedResponse->invocationId,
                'event_count' => $eventCount,
                'text_delta_count' => $textDeltaCount,
                'tool_call_count' => count($toolCalls),
                'tool_result_count' => count($toolResults),
            ]));
        } else {
            $response = $agent->prompt($prompt, (array) $attachments, $this->provider, $this->model, $timeout);

            if ($response->hasPendingApprovals()) {
                throw new AgentApprovalRequiredException($this->id);
            }

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

        if ($this->usageChannel !== null) {
            $writes[$this->usageChannel] = $usage->toArray();
        }

        if ($this->metaChannel !== null) {
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

    protected function assertSupportedStreamingMappings(): void
    {
        $unsupported = [];

        if ($this->structuredChannel !== null) {
            $unsupported[] = 'structured output';
        }

        if ($this->stepsChannel !== null) {
            $unsupported[] = 'steps';
        }

        if ($unsupported !== []) {
            throw new RuntimeException(sprintf(
                'Agent node [%s] cannot write %s from a Laravel AI stream.',
                $this->id,
                implode(' or ', $unsupported),
            ));
        }
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
