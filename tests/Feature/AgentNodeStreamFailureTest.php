<?php

use Heiner\AgentGraph\Exceptions\AgentStreamException;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\LaravelAi\AgentNode;
use Heiner\AgentGraph\Runtime\NodeResult;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error as StreamError;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

it('fails a node on a terminal stream error without committing partial writes or continuing', function (bool $partialText) {
    $downstreamCalls = 0;
    $deliveredText = [];
    $error = (new StreamError('error-1', 'server_error', 'The provider stream failed.', false, 2))->withInvocationId('stream-1');
    $events = $partialText
        ? [(new TextDelta('delta-1', 'message-1', 'incomplete', 1))->withInvocationId('stream-1'), $error]
        : [$error];

    AgentGraph::define(StateGraph::make('failing_stream')
        ->state(['answer' => 'string', 'usage' => 'array', 'stream_events' => 'array', 'after' => 'bool'])
        ->node('model', AgentNode::make('model')
            ->agent(new StreamFailureTestAgent($events))
            ->prompt('hello')
            ->stream()
            ->writeTextTo('answer')
            ->writeUsageTo('usage')
            ->writeStreamEventsTo('stream_events')
            ->onTextDelta(function (string $delta) use (&$deliveredText): void {
                $deliveredText[] = $delta;
            }))
        ->node('after', function () use (&$downstreamCalls): NodeResult {
            $downstreamCalls++;

            return NodeResult::end(['after' => true]);
        })
        ->edge(StateGraph::START, 'model')
        ->edge('model', 'after')
        ->compile());

    $initial = ['answer' => 'previous answer', 'usage' => [], 'stream_events' => [], 'after' => false];
    $run = AgentGraph::graph('failing_stream')->input($initial)->run();

    expect($run->failed())->toBeTrue()
        ->and($run->state())->toBe($initial)
        ->and($run->error()['exception_class'])->toBe(AgentStreamException::class)
        ->and($run->error()['message'])->toContain('The provider stream failed.')
        ->and($downstreamCalls)->toBe(0)
        ->and($deliveredText)->toBe($partialText ? ['incomplete'] : [])
        ->and(app('agent-graph.writes')->listForRun($run->runId()))->toBe([]);
})->with([false, true]);

it('allows recoverable stream notices when the provider continues successfully', function () {
    $events = [
        (new StreamError('notice-1', 'temporary_error', 'The provider is retrying.', true, 1))->withInvocationId('stream-1'),
        (new TextDelta('delta-1', 'message-1', 'complete answer', 2))->withInvocationId('stream-1'),
        (new StreamEnd('end-1', 'stop', new Usage(2, 3), 3))->withInvocationId('stream-1'),
    ];

    AgentGraph::define(StateGraph::make('recovered_stream')
        ->state(['answer' => 'string', 'usage' => 'array', 'stream_events' => 'array'])
        ->node('model', AgentNode::make('model')
            ->agent(new StreamFailureTestAgent($events))
            ->prompt('hello')
            ->stream()
            ->writeTextTo('answer')
            ->writeUsageTo('usage')
            ->writeStreamEventsTo('stream_events'))
        ->edge(StateGraph::START, 'model')
        ->compile());

    $run = AgentGraph::graph('recovered_stream')->run();

    expect($run->completed())->toBeTrue()
        ->and($run->error())->toBeNull()
        ->and($run->state('answer'))->toBe('complete answer')
        ->and($run->state('usage')['completion_tokens'])->toBe(3)
        ->and($run->state('stream_events')[0]['recoverable'])->toBeTrue();
});

final class StreamFailureTestAgent implements Agent
{
    use Promptable;

    public function __construct(private array $events) {}

    public function instructions(): string
    {
        return 'Test streaming behavior.';
    }

    public function stream(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse('stream-1', fn () => $this->events, new Meta('fake', 'fake-model'));
    }
}
