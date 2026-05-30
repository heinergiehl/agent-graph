<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Events\GraphStreamDelta;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\LaravelAi\AgentNode;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunEvent;
use Heiner\AgentGraph\Runtime\RunResult;
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

    AgentGraph::define(
        StateGraph::make('streaming_agent_answer')
            ->state(['input' => 'string', 'answer' => 'string|null', 'usage' => 'array'])
            ->node('answer', AgentNode::make('streaming_answer')
                ->agent(FakeStreamingSupportAgent::class)
                ->prompt(fn (array $state) => $state['input'])
                ->stream()
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
        ->and(collect($run->events())->where(fn (RunEvent $event): bool => $event->type() === 'stream.delta')->last()->payload()['delta'])->toBe('stream');

    Event::assertDispatched(GraphStreamDelta::class, 3);
    Event::assertDispatched(GraphStreamDelta::class, fn (GraphStreamDelta $event): bool => $event->payload['delta'] === 'stream');

    $streamTraces = collect(app('agent-graph.traces')->listForRun($run->runId()))
        ->where('event', 'stream.delta')
        ->values();

    expect($streamTraces)->toHaveCount(3)
        ->and($streamTraces[0]['payload'])->toHaveKeys(['delta', 'message_id', 'invocation_id']);
});

it('invokes AgentNode text delta callbacks while streaming', function () {
    app()->bind(FakeStreamingSupportAgent::class, fn () => new FakeStreamingSupportAgent);
    $deltas = [];

    AgentGraph::define(
        StateGraph::make('streaming_agent_callback')
            ->state(['input' => 'string', 'answer' => 'string|null'])
            ->node('answer', AgentNode::make('streaming_callback')
                ->agent(FakeStreamingSupportAgent::class)
                ->prompt(fn (array $state) => $state['input'])
                ->stream()
                ->onTextDelta(function (string $delta, array $payload, NodeContext $context) use (&$deltas): void {
                    $deltas[] = [
                        'delta' => $delta,
                        'payload' => $payload,
                        'run_id' => $context->runId(),
                    ];
                })
                ->writeTextTo('answer'))
            ->edge(StateGraph::START, 'answer')
            ->edge('answer', StateGraph::END)
    );

    $run = AgentGraph::graph('streaming_agent_callback')
        ->thread('stream-callback-thread')
        ->input(['input' => 'Hello'])
        ->run();

    expect($run->completed())->toBeTrue()
        ->and($run->state('answer'))->toBe('Hello from stream')
        ->and($deltas)->toHaveCount(3)
        ->and($deltas[0]['delta'])->toBe('Hello ')
        ->and($deltas[0]['payload'])->toHaveKeys(['agent_node', 'delta', 'message_id', 'invocation_id'])
        ->and($deltas[0]['run_id'])->toBe($run->runId());
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

it('sanitizes default graph tool names for provider compatibility', function () {
    $tool = AgentGraph::tool('filament-agentic-chatbot.workflow.123/alpha');

    expect($tool->name())->toBe('run_filament_agentic_chatbot_workflow_123_alpha');
});

it('rejects invalid custom graph tool names', function () {
    expect(fn () => AgentGraph::tool('support')->name('bad name with spaces'))
        ->toThrow(InvalidArgumentException::class, 'Invalid AI tool name');
});

it('describes the GraphTool input schema', function () {
    $tool = AgentGraph::tool('tool_graph_schema');
    $schema = $tool->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys(['thread_id', 'run_id', 'interrupt_id', 'input'])
        ->and($schema['thread_id']->toArray()['type'])->toContain('string')
        ->and($schema['input']->toArray()['type'])->toContain('object');
});

it('maps GraphTool input output and run metadata through extension hooks', function () {
    AgentGraph::define(
        StateGraph::make('tool_hook_graph')
            ->state(['message' => 'string|null', 'source' => 'string|null', 'answer' => 'string|null'])
            ->node('answer', ToolHookAnswerNode::class)
            ->edge(StateGraph::START, 'answer')
            ->edge('answer', StateGraph::END)
    );

    $tool = AgentGraph::tool('tool_hook_graph')
        ->thread(fn (Request $request) => 'thread-'.$request['conversation'])
        ->input(fn (Request $request): array => [
            'message' => $request['body'],
            'source' => 'hooked-input',
        ])
        ->meta(fn (Request $request): array => [
            'tool' => 'hook-test',
            'conversation' => $request['conversation'],
        ])
        ->output(fn (RunResult $run, Request $request): array => [
            'ok' => $run->completed(),
            'run' => $run->runId(),
            'thread' => $run->threadId(),
            'answer' => $run->state('answer'),
            'body' => $request['body'],
        ]);

    $payload = json_decode((string) $tool->handle(new Request([
        'conversation' => 'abc',
        'body' => 'hello',
    ])), true);

    expect($payload)->toMatchArray([
        'ok' => true,
        'thread' => 'thread-abc',
        'answer' => 'Hook handled hello from hooked-input',
        'body' => 'hello',
    ]);

    $snapshot = AgentGraph::inspect($payload['run']);

    expect($snapshot->meta())->toMatchArray([
        'tool' => 'hook-test',
        'conversation' => 'abc',
    ]);
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

final class ToolHookAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([
            'answer' => 'Hook handled '.$context->state('message').' from '.$context->state('source'),
        ]);
    }
}
