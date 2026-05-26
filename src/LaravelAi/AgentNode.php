<?php

namespace Heiner\AgentGraph\LaravelAi;

use Closure;
use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Events\GraphStreamDelta;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunEventDispatcher;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Streaming\Events\TextDelta;
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

            foreach ($response as $event) {
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

                    if ($this->textDeltaCallback !== null) {
                        ($this->textDeltaCallback)($event->delta);
                    }
                }
            }

            $usage = $response->usage;
            $responseMeta = null;
        } else {
            $response = $agent->prompt($prompt, (array) $attachments, $this->provider, $this->model, $this->timeout);
            $text = $response->text;
            $usage = $response->usage;
            $responseMeta = $response->meta;
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
}
