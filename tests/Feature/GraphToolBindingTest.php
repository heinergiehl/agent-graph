<?php

use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Laravel\Ai\Tools\Request;

it('rejects another graph or thread without changing its run or interrupt', function (string $adapter, string $mismatch) {
    $resumed = 0;

    foreach (['bound_graph', 'other_graph'] as $graphKey) {
        defineAdapterBindingGraph($graphKey, $resumed);
    }

    $graphKey = $mismatch === 'thread' ? 'bound_graph' : 'other_graph';
    $threadId = $mismatch === 'graph' ? 'bound-thread' : 'other-thread';
    $foreign = AgentGraph::graph($graphKey)->thread($threadId)->input(['private' => 'private state'])->run();
    $before = AgentGraph::inspect($foreign->runId());
    $payload = [
        'run_id' => $foreign->runId(),
        'interrupt_id' => $foreign->interrupt()['interrupt_id'],
        'answer' => 'untrusted answer',
    ];
    $resolverCalls = 0;

    if ($adapter === 'session') {
        expect(fn () => AgentGraph::session('bound_graph', 'bound-thread')->resume($payload))
            ->toThrow(InvalidArgumentException::class);
    } else {
        $tool = $adapter === 'tool'
            ? AgentGraph::tool('bound_graph')
            : AgentGraph::durableTool('bound_graph');
        $tool->thread(function (Request $request) use (&$resolverCalls): string {
            $resolverCalls++;

            return 'bound-thread';
        });
        $response = json_decode($tool->handle(new Request([
            'run_id' => $payload['run_id'],
            'interrupt_id' => $payload['interrupt_id'],
            'thread_id' => $threadId,
            'input' => ['answer' => $payload['answer']],
        ])), true, flags: JSON_THROW_ON_ERROR);

        expect($response['status'])->toBe('failed')
            ->and($response['state'])->toBe([])
            ->and($response['interrupt'])->toBeNull()
            ->and($response['error']['exception_class'])->toBe(InvalidArgumentException::class)
            ->and($resolverCalls)->toBe(1);
    }

    $after = AgentGraph::inspect($foreign->runId());

    expect($resumed)->toBe(0)
        ->and($after->run())->toEqual($before->run())
        ->and($after->checkpoint())->toEqual($before->checkpoint())
        ->and($after->interrupt())->toEqual($before->interrupt());
})->with(['tool', 'durable-tool', 'session'])->with(['graph', 'thread', 'graph-and-thread']);

it('resumes the bound graph and thread with an explicit run id', function (string $adapter) {
    $resumed = 0;
    defineAdapterBindingGraph('bound_graph', $resumed);
    $started = AgentGraph::graph('bound_graph')->thread('bound-thread')->run();
    $payload = [
        'run_id' => $started->runId(),
        'interrupt_id' => $started->interrupt()['interrupt_id'],
        'answer' => 'accepted answer',
    ];
    $resolverCalls = 0;

    if ($adapter === 'session') {
        $run = AgentGraph::session('bound_graph', 'bound-thread')->resume($payload, strict: true);

        expect($run->completed())->toBeTrue()
            ->and($run->threadId())->toBe('bound-thread')
            ->and($run->state('answer'))->toBe('accepted answer');
    } else {
        $tool = $adapter === 'tool'
            ? AgentGraph::tool('bound_graph')
            : AgentGraph::durableTool('bound_graph')->strictResume();
        $tool->thread(function (Request $request) use (&$resolverCalls): string {
            $resolverCalls++;

            return 'bound-thread';
        });
        $response = json_decode($tool->handle(new Request([
            'run_id' => $payload['run_id'],
            'interrupt_id' => $payload['interrupt_id'],
            'thread_id' => 'ignored-model-thread',
            'input' => ['answer' => $payload['answer']],
        ])), true, flags: JSON_THROW_ON_ERROR);

        expect($response['status'])->toBe('completed')
            ->and($response['thread_id'])->toBe('bound-thread')
            ->and($response['state']['answer'])->toBe('accepted answer')
            ->and($resolverCalls)->toBe(1);
    }

    expect($resumed)->toBe(1);
})->with(['tool', 'durable-tool', 'session']);

it('does not skip a graph tool thread resolver when resume context is missing', function () {
    $resumed = 0;
    defineAdapterBindingGraph('bound_graph', $resumed);
    $started = AgentGraph::graph('bound_graph')->thread('bound-thread')->run();
    $before = AgentGraph::inspect($started->runId());
    $tool = AgentGraph::tool('bound_graph')->thread(function (Request $request): string {
        if (! isset($request['conversation_id'])) {
            throw new InvalidArgumentException('Conversation context is required.');
        }

        return $request['conversation_id'];
    });
    $response = json_decode($tool->handle(new Request([
        'run_id' => $started->runId(),
        'interrupt_id' => $started->interrupt()['interrupt_id'],
        'input' => ['answer' => 'untrusted answer'],
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($response['status'])->toBe('failed')
        ->and($response['error']['message'])->toBe('Conversation context is required.')
        ->and($resumed)->toBe(0)
        ->and(AgentGraph::inspect($started->runId())->run())->toEqual($before->run())
        ->and(AgentGraph::inspect($started->runId())->interrupt())->toEqual($before->interrupt());
});

it('checks a request thread when a graph tool has no configured resolver', function () {
    $resumed = 0;
    defineAdapterBindingGraph('bound_graph', $resumed);
    $started = AgentGraph::graph('bound_graph')->thread('bound-thread')->run();
    $response = json_decode(AgentGraph::tool('bound_graph')->handle(new Request([
        'thread_id' => 'other-thread',
        'run_id' => $started->runId(),
        'interrupt_id' => $started->interrupt()['interrupt_id'],
        'input' => ['answer' => 'untrusted answer'],
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($response['status'])->toBe('failed')
        ->and($resumed)->toBe(0)
        ->and(AgentGraph::inspect($started->runId())->status())->toBe('interrupted');
});

it('preserves run-id-only graph tool resumes within the configured graph', function () {
    $resumed = 0;
    defineAdapterBindingGraph('bound_graph', $resumed);
    $started = AgentGraph::graph('bound_graph')->thread('bound-thread')->run();
    $response = json_decode(AgentGraph::tool('bound_graph')->handle(new Request([
        'run_id' => $started->runId(),
        'interrupt_id' => $started->interrupt()['interrupt_id'],
        'input' => ['answer' => 'accepted answer'],
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($response['status'])->toBe('completed')
        ->and($response['thread_id'])->toBe('bound-thread')
        ->and($resumed)->toBe(1);
});

it('does not infer a durable tool session thread from a supplied run id', function () {
    $resumed = 0;
    defineAdapterBindingGraph('bound_graph', $resumed);
    $started = AgentGraph::graph('bound_graph')->thread('bound-thread')->run();
    $response = json_decode(AgentGraph::durableTool('bound_graph')->handle(new Request([
        'run_id' => $started->runId(),
        'interrupt_id' => $started->interrupt()['interrupt_id'],
        'input' => ['answer' => 'untrusted answer'],
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($response['status'])->toBe('failed')
        ->and($resumed)->toBe(0)
        ->and(AgentGraph::inspect($started->runId())->status())->toBe('interrupted');
});

function defineAdapterBindingGraph(string $graphKey, int &$resumed): void
{
    AgentGraph::define(StateGraph::make($graphKey)
        ->state(['answer' => 'string|null', 'private' => 'string|null'])
        ->node('wait', function (NodeContext $context) use (&$resumed): NodeResult {
            if ($context->hasResumePayload()) {
                $resumed++;

                return NodeResult::end();
            }

            return NodeResult::interrupt('input', ['prompt' => 'Provide an answer.']);
        })
        ->edge(StateGraph::START, 'wait')
        ->compile());
}
