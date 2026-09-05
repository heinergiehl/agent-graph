<?php

use Heiner\AgentGraph\Exceptions\AgentApprovalRequiredException;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\LaravelAi\AgentNode;
use Heiner\AgentGraph\Runtime\NodeResult;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;

it('rejects native tool approvals without retrying or committing partial writes', function (bool $stream) {
    $agent = new ApprovalTestAgent;
    $downstream = 0;
    AgentGraph::define(StateGraph::make('native_approval')
        ->state(['answer' => 'string', 'tool_calls' => 'array', 'stream_events' => 'array'])
        ->node('model', AgentNode::make('model')
            ->agent($agent)
            ->prompt('Synthetic fixture')
            ->stream($stream)
            ->writeTextTo('answer')
            ->writeToolCallsTo('tool_calls')
            ->writeStreamEventsTo('stream_events'))
        ->retry('model', maxAttempts: 3)
        ->node('after', function () use (&$downstream): NodeResult {
            $downstream++;

            return NodeResult::end();
        })
        ->edge(StateGraph::START, 'model')
        ->edge('model', 'after'));

    $initial = ['answer' => 'previous answer'];
    $run = AgentGraph::graph('native_approval')->input($initial)->run();

    expect($run->failed())->toBeTrue()
        ->and($run->error()['exception_class'])->toBe(AgentApprovalRequiredException::class)
        ->and($run->state())->toBe($initial)
        ->and($agent->invocations)->toBe(1)
        ->and($downstream)->toBe(0)
        ->and(app('agent-graph.writes')->listForRun($run->runId()))->toBe([]);
})->with([false, true]);

final class ApprovalTestAgent implements Agent
{
    use Promptable;

    public int $invocations = 0;

    public function instructions(): string
    {
        return 'Synthetic approval fixture.';
    }

    public function prompt(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        $this->invocations++;

        return AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call-1', 'synthetic_write', ['record' => 'fixture']),
        ]);
    }

    public function stream(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $this->invocations++;

        return new StreamableAgentResponse('fixture', fn () => [
            (new TextDelta('delta', 'message', 'partial answer', 1))->withInvocationId('fixture'),
            (new ToolApprovalRequest('approval', collect([
                new PendingApproval('call-1', 'synthetic_write', ['record' => 'fixture']),
            ]), 2))->withInvocationId('fixture'),
            (new StreamEnd('end', 'tool_calls', new Usage, 3))->withInvocationId('fixture'),
        ], new Meta('fake', 'fixture'));
    }
}
