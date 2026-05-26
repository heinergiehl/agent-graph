<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Events\GraphStreamDelta;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\LaravelAi\AgentNode;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\Request;

it('runs a Laravel AI agent inside an AgentNode', function () {
    app()->bind(FakeSupportAgent::class, fn () => new FakeSupportAgent);

    AgentGraph::define(
        StateGraph::make('agent_answer')
            ->state(['input' => 'string', 'answer' => 'string|null', 'usage' => 'array'])
            ->node('answer', AgentNode::make('answer')
                ->agent(FakeSupportAgent::class)
                ->prompt(fn (array $state) => $state['input'])
                ->writeTextTo('answer')
                ->writeUsageTo('usage'))
            ->edge(StateGraph::START, 'answer')
            ->edge('answer', StateGraph::END)
    );

    $run = AgentGraph::graph('agent_answer')
        ->thread('agent-thread')
        ->input(['input' => 'Hello'])
        ->run();

    expect($run->completed())->toBeTrue()
        ->and($run->state('answer'))->toBe('AI response to Hello')
        ->and($run->state('usage')['prompt_tokens'])->toBe(3);
});

it('dispatches AgentGraph stream events for streamed Laravel AI deltas', function () {
    Event::fake([GraphStreamDelta::class]);
    app()->bind(FakeStreamingSupportAgent::class, fn () => new FakeStreamingSupportAgent);
    $streamedDeltas = [];

    AgentGraph::define(
        StateGraph::make('streaming_agent_answer')
            ->state(['input' => 'string', 'answer' => 'string|null', 'usage' => 'array'])
            ->node('answer', AgentNode::make('streaming_answer')
                ->agent(FakeStreamingSupportAgent::class)
                ->prompt(fn (array $state) => $state['input'])
                ->stream()
                ->onTextDelta(function (string $delta) use (&$streamedDeltas): void {
                    $streamedDeltas[] = $delta;
                })
                ->writeTextTo('answer')
                ->writeUsageTo('usage'))
            ->edge(StateGraph::START, 'answer')
            ->edge('answer', StateGraph::END)
    );

    $run = AgentGraph::graph('streaming_agent_answer')
        ->thread('stream-thread')
        ->input(['input' => 'Hello'])
        ->collectEvents()
        ->run();

    expect($run->completed())->toBeTrue()
        ->and($run->state('answer'))->toBe('Hello from stream')
        ->and($run->state('usage')['completion_tokens'])->toBe(4)
        ->and(collect($run->events())->where(fn (RunEvent $event): bool => $event->type() === 'stream.delta'))->toHaveCount(3)
        ->and(collect($run->events())->where(fn (RunEvent $event): bool => $event->type() === 'stream.delta')->last()->payload()['delta'])->toBe('stream')
        ->and($streamedDeltas)->toBe(['Hello ', 'from ', 'stream']);

    Event::assertDispatched(GraphStreamDelta::class, 3);
    Event::assertDispatched(GraphStreamDelta::class, fn (GraphStreamDelta $event): bool => $event->payload['delta'] === 'stream');

    $streamTraces = collect(app('agent-graph.traces')->listForRun($run->runId()))
        ->where('event', 'stream.delta')
        ->values();

    expect($streamTraces)->toHaveCount(3)
        ->and($streamTraces[0]['payload'])->toHaveKeys(['delta', 'message_id', 'invocation_id']);
});

it('exposes a graph as a Laravel AI tool and returns structured json', function () {
    AgentGraph::define(
        StateGraph::make('tool_graph')
            ->state(['message' => 'string|null', 'answer' => 'string|null'])
            ->node('answer', ToolAnswerNode::class)
            ->edge(StateGraph::START, 'answer')
            ->edge('answer', StateGraph::END)
    );

    $tool = AgentGraph::tool('tool_graph')
        ->name('run_tool_graph')
        ->description('Run the tool graph.')
        ->thread(fn (Request $request) => $request['thread_id']);

    $payload = json_decode((string) $tool->handle(new Request([
        'thread_id' => 'tool-thread',
        'input' => ['message' => 'hello'],
    ])), true);

    expect($tool->name())->toBe('run_tool_graph')
        ->and($payload['status'])->toBe('completed')
        ->and($payload)->toHaveKeys(['status', 'run_id', 'thread_id', 'state', 'interrupt', 'error'])
        ->and($payload['state']['answer'])->toBe('Tool handled hello');
});

it('describes the GraphTool input schema', function () {
    $tool = AgentGraph::tool('tool_graph_schema');
    $schema = $tool->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys(['thread_id', 'run_id', 'interrupt_id', 'input'])
        ->and($schema['thread_id']->toArray()['type'])->toContain('string')
        ->and($schema['input']->toArray()['type'])->toContain('object');
});

it('returns stable GraphTool json for interrupted resumed and failed runs', function () {
    AgentGraph::define(
        StateGraph::make('tool_interrupt_graph')
            ->state(['question' => 'string|null', 'answer' => 'string|null'])
            ->node('ask', ToolInterruptNode::class)
            ->node('answer', ToolResumeAnswerNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', 'answer')
            ->edge('answer', StateGraph::END)
    );

    $tool = AgentGraph::tool('tool_interrupt_graph')->thread(fn (Request $request) => $request['thread_id']);

    $interrupted = json_decode((string) $tool->handle(new Request([
        'thread_id' => 'tool-interrupt-thread',
        'input' => [],
    ])), true);

    expect($interrupted['status'])->toBe('interrupted')
        ->and($interrupted['interrupt']['type'])->toBe('input')
        ->and($interrupted['error'])->toBeNull();

    $completed = json_decode((string) $tool->handle(new Request([
        'run_id' => $interrupted['run_id'],
        'interrupt_id' => $interrupted['interrupt']['interrupt_id'],
        'input' => ['question' => 'shipping'],
    ])), true);

    expect($completed['status'])->toBe('completed')
        ->and($completed['state']['answer'])->toBe('Answered shipping')
        ->and($completed['interrupt'])->toBeNull()
        ->and($completed['error'])->toBeNull();

    AgentGraph::define(
        StateGraph::make('tool_failed_graph')
            ->state(['answer' => 'string|null'])
            ->node('fail', ToolFailureNode::class)
            ->edge(StateGraph::START, 'fail')
            ->edge('fail', StateGraph::END)
    );

    $failed = json_decode((string) AgentGraph::tool('tool_failed_graph')->handle(new Request([
        'thread_id' => 'tool-failed-thread',
        'input' => [],
    ])), true);

    expect($failed['status'])->toBe('failed')
        ->and($failed['error']['message'])->toBe('tool failed');
});

class FakeSupportAgent implements Agent
{
    public function instructions(): Stringable|string
    {
        return 'Be helpful.';
    }

    public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        return new AgentResponse('invocation-1', 'AI response to '.$prompt, new Usage(promptTokens: 3, completionTokens: 5), new Meta('fake', 'fake-model'));
    }

    public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse('stream-1', fn () => []);
    }

    public function queue(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        throw new RuntimeException('Not used.');
    }

    public function broadcast(string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        throw new RuntimeException('Not used.');
    }

    public function broadcastNow(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        throw new RuntimeException('Not used.');
    }

    public function broadcastOnQueue(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        throw new RuntimeException('Not used.');
    }
}

final class FakeStreamingSupportAgent extends FakeSupportAgent
{
    public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse('stream-1', function () {
            yield (new TextDelta('delta-1', 'message-1', 'Hello ', 1))->withInvocationId('stream-1');
            yield (new TextDelta('delta-2', 'message-1', 'from ', 2))->withInvocationId('stream-1');
            yield (new TextDelta('delta-3', 'message-1', 'stream', 3))->withInvocationId('stream-1');
            yield (new StreamEnd('end-1', 'stop', new Usage(promptTokens: 2, completionTokens: 4), 4))->withInvocationId('stream-1');
        }, new Meta('fake', 'fake-stream-model'));
    }
}

final class ToolAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'Tool handled '.$context->state('message')]);
    }
}

final class ToolInterruptNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->state('question') === null) {
            return NodeResult::interrupt('input', ['prompt' => 'What should I answer?']);
        }

        return NodeResult::write([]);
    }
}

final class ToolResumeAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['answer' => 'Answered '.$context->state('question')]);
    }
}

final class ToolFailureNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::fail('tool failed');
    }
}
