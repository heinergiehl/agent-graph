<?php

use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\LaravelAi\AgentNode;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ResponseToolCall;
use Laravel\Ai\Responses\Data\ToolResult as ResponseToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as StreamToolCall;
use Laravel\Ai\Streaming\Events\ToolResult as StreamToolResult;

it('writes structured output and tool metadata from public Laravel AI response DTOs', function () {
    app()->instance(EnrichedAgent::class, new EnrichedAgent);

    AgentGraph::define(
        StateGraph::make('enriched_agent_node')
            ->state([
                'answer' => 'string',
                'structured' => 'array',
                'tool_calls' => 'array',
                'tool_results' => 'array',
                'steps' => 'array',
            ])
            ->node('answer', AgentNode::make('answer')
                ->agent(EnrichedAgent::class)
                ->prompt('hello')
                ->writeTextTo('answer')
                ->writeStructuredTo('structured')
                ->writeToolCallsTo('tool_calls')
                ->writeToolResultsTo('tool_results')
                ->writeStepsTo('steps'))
            ->edge('__start__', 'answer')
            ->compile(),
    );

    $run = AgentGraph::graph('enriched_agent_node')->thread('agent-enriched-thread')->run();

    $toolCalls = $run->state('tool_calls');
    $toolResults = $run->state('tool_results');
    $steps = $run->state('steps');

    expect($run->status())->toBe('completed')
        ->and($run->state('answer'))->toBe('plain text')
        ->and($run->state('structured'))->toBe(['sentiment' => 'positive'])
        ->and($toolCalls[0]['name'])->toBe('lookup')
        ->and($toolResults[0]['result']['value'])->toBe(42)
        ->and($steps[0]['name'])->toBe('step-one');
});

it('writes stream events from public Laravel AI streaming events', function () {
    app()->instance(StreamEventAgent::class, new StreamEventAgent);

    AgentGraph::define(
        StateGraph::make('stream_event_agent_node')
            ->state([
                'answer' => 'string',
                'stream_events' => 'array',
                'tool_calls' => 'array',
                'tool_results' => 'array',
            ])
            ->node('answer', AgentNode::make('answer')
                ->agent(StreamEventAgent::class)
                ->prompt('hello')
                ->stream()
                ->writeTextTo('answer')
                ->writeStreamEventsTo('stream_events')
                ->writeToolCallsTo('tool_calls')
                ->writeToolResultsTo('tool_results'))
            ->edge('__start__', 'answer')
            ->compile(),
    );

    $run = AgentGraph::graph('stream_event_agent_node')->thread('stream-event-thread')->run();

    $toolCalls = $run->state('tool_calls');
    $toolResults = $run->state('tool_results');

    expect($run->status())->toBe('completed')
        ->and($run->state('answer'))->toBe('Hello')
        ->and($run->state('stream_events'))->toHaveCount(4)
        ->and($toolCalls[0]['name'])->toBe('lookup')
        ->and($toolResults[0]['result']['ok'])->toBeTrue();
});

class EnrichedAgent implements Agent
{
    public function instructions(): Stringable|string
    {
        return 'test';
    }

    public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        return (new StructuredAgentResponse(
            'invoke-1',
            ['sentiment' => 'positive'],
            'plain text',
            new Usage(1, 1),
            new Meta('fake', 'fake-model'),
        ))->withToolCallsAndResults(
            new Collection([new ResponseToolCall('call-1', 'lookup', ['id' => 1])]),
            new Collection([new ResponseToolResult('result-1', 'lookup', ['id' => 1], ['value' => 42])]),
        )->withSteps(new Collection([(object) ['name' => 'step-one']]));
    }

    public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse('unused', fn () => []);
    }

    public function queue(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        throw new RuntimeException('unused');
    }

    public function broadcast(string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        throw new RuntimeException('unused');
    }

    public function broadcastNow(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        throw new RuntimeException('unused');
    }

    public function broadcastOnQueue(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        throw new RuntimeException('unused');
    }
}

final class StreamEventAgent extends EnrichedAgent
{
    public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        throw new RuntimeException('unused');
    }

    public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse('stream-1', function () {
            yield (new TextDelta('delta-1', 'message-1', 'Hello', 1))->withInvocationId('stream-1');
            yield (new StreamToolCall('tool-call-event', new ResponseToolCall('call-1', 'lookup', ['id' => 1]), 2))->withInvocationId('stream-1');
            yield (new StreamToolResult('tool-result-event', new ResponseToolResult('result-1', 'lookup', ['id' => 1], ['ok' => true]), true, null, 3))->withInvocationId('stream-1');
            yield (new StreamEnd('end-1', 'stop', new Usage(1, 1), 4))->withInvocationId('stream-1');
        }, new Meta('fake', 'fake-stream'));
    }
}
