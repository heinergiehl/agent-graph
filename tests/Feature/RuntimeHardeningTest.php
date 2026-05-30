<?php

use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Contracts\CheckpointStore;
use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Contracts\MemoryStore;
use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Contracts\NodeExecutionStore;
use Heiner\AgentGraph\Contracts\RunStore;
use Heiner\AgentGraph\Contracts\TaskStore;
use Heiner\AgentGraph\Contracts\TraceStore;
use Heiner\AgentGraph\Contracts\WriteStore;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\ConcurrencyPolicy;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Graph\TimeoutPolicy;
use Heiner\AgentGraph\Runtime\GraphRuntime;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\RunEventDispatcher;
use Heiner\AgentGraph\Support\DelaySchedulerResolver;

it('strict resume rejects unknown state keys while normal resume stays permissive', function () {
    AgentGraph::define(
        StateGraph::make('strict_resume_graph')
            ->state(['answer' => 'string'])
            ->node('ask', StrictResumeAskNode::class)
            ->node('answer', StrictResumeAnswerNode::class)
            ->edge('__start__', 'ask')
            ->edge('ask', 'answer')
            ->compile(),
    );

    $run = AgentGraph::graph('strict_resume_graph')->thread('strict-resume-thread')->run();

    expect(fn () => AgentGraph::resumeStrict($run->runId(), [
        'interrupt_id' => $run->interrupt()['interrupt_id'],
        'unexpected' => 'no',
    ]))->toThrow(InvalidArgumentException::class, 'unknown state key [unexpected]');

    $completed = AgentGraph::resume($run->runId(), [
        'interrupt_id' => $run->interrupt()['interrupt_id'],
        'unexpected' => 'allowed',
        'answer' => 'done',
    ]);

    expect($completed->status())->toBe('completed')
        ->and($completed->state('answer'))->toBe('done');
});

it('compiles timeout and concurrency policies and fails nodes that exceed timeout', function () {
    $graph = StateGraph::make('timeout_policy_graph')
        ->state(['done' => 'bool'])
        ->node('slow', SlowNode::class)
        ->timeout('slow', 0.01)
        ->concurrency('slow', limit: 1, key: 'slow-node')
        ->edge('__start__', 'slow')
        ->compile();

    $policy = $graph->nodePolicy('slow');

    expect($policy->timeoutPolicy())->toBeInstanceOf(TimeoutPolicy::class)
        ->and($policy->concurrencyPolicy())->toBeInstanceOf(ConcurrencyPolicy::class);

    AgentGraph::define($graph);

    $run = AgentGraph::graph('timeout_policy_graph')->thread('timeout-thread')->run();

    expect($run->status())->toBe('failed')
        ->and($run->error()['message'])->toContain('timed out');
});

it('applies per run max step runtime options without mutating global config', function () {
    config()->set('agent-graph.max_steps', 10);

    AgentGraph::define(
        StateGraph::make('runtime_options_max_steps')
            ->state(['count' => 'int|null'])
            ->node('loop', RuntimeOptionsLoopNode::class)
            ->edge(StateGraph::START, 'loop')
            ->edge('loop', 'loop')
            ->compile(),
    );

    $run = AgentGraph::graph('runtime_options_max_steps')
        ->thread('runtime-options-thread')
        ->options(['max_steps' => 1])
        ->run();

    expect($run->status())->toBe('failed')
        ->and($run->error()['code'])->toBe('max_steps_exceeded')
        ->and(app('agent-graph.checkpoints')->listForRun($run->runId()))->toHaveCount(1)
        ->and(config('agent-graph.max_steps'))->toBe(10);
});

it('rejects resume attempts for terminal runs without resolving pending interrupts', function (string $status) {
    AgentGraph::define(
        StateGraph::make('terminal_resume_guard_'.$status)
            ->state(['answer' => 'string|null'])
            ->node('ask', TerminalResumeAskNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', StateGraph::END)
            ->compile(),
    );

    $run = AgentGraph::graph('terminal_resume_guard_'.$status)->thread('terminal-resume-'.$status)->run();
    $interrupt = $run->interrupt();
    app('agent-graph.runs')->update($run->runId(), ['status' => $status]);

    expect(fn () => AgentGraph::resume($run->runId(), [
        'interrupt_id' => $interrupt['interrupt_id'],
        'answer' => 'late',
    ]))->toThrow(RuntimeException::class, "Run [{$run->runId()}] is {$status} and cannot be resumed.");

    expect(app('agent-graph.runs')->find($run->runId())['status'])->toBe($status)
        ->and(app('agent-graph.interrupts')->pendingForRun($run->runId())['interrupt_id'])->toBe($interrupt['interrupt_id']);
})->with(['completed', 'cancelled', 'failed']);

it('rejects state edit resume attempts for terminal runs before resolving the interrupt', function () {
    AgentGraph::define(
        StateGraph::make('terminal_state_edit_guard')
            ->state(['draft' => 'string|null'])
            ->node('review', TerminalStateEditNode::class)
            ->edge(StateGraph::START, 'review')
            ->edge('review', StateGraph::END)
            ->compile(),
    );

    $run = AgentGraph::graph('terminal_state_edit_guard')->thread('terminal-state-edit')->run();
    $interrupt = $run->interrupt();
    app('agent-graph.runs')->update($run->runId(), ['status' => 'completed']);

    expect(fn () => AgentGraph::resumeWithStateEdit($run->runId(), $interrupt['interrupt_id'], ['draft' => 'updated']))
        ->toThrow(RuntimeException::class, "Run [{$run->runId()}] is completed and cannot be resumed.");

    expect(app('agent-graph.interrupts')->pendingForRun($run->runId())['interrupt_id'])->toBe($interrupt['interrupt_id']);
});

it('rejects cancelling terminal runs and leaves their status unchanged', function (string $status) {
    AgentGraph::define(
        StateGraph::make('terminal_cancel_guard_'.$status)
            ->state(['done' => 'bool|null'])
            ->node('done', TerminalDoneNode::class)
            ->edge(StateGraph::START, 'done')
            ->compile(),
    );

    $run = AgentGraph::graph('terminal_cancel_guard_'.$status)->thread('terminal-cancel-'.$status)->run();
    app('agent-graph.runs')->update($run->runId(), ['status' => $status]);

    expect(fn () => AgentGraph::cancel($run->runId()))
        ->toThrow(RuntimeException::class, "Run [{$run->runId()}] is {$status} and cannot be cancelled.");

    expect(app('agent-graph.runs')->find($run->runId())['status'])->toBe($status);
})->with(['completed', 'cancelled', 'failed']);

it('runs durable sessions under a session lock before checking active runs', function () {
    $locks = new RecordingLockProvider;
    $runtime = new GraphRuntime(
        container: app(),
        runs: app(RunStore::class),
        checkpoints: app(CheckpointStore::class),
        writes: app(WriteStore::class),
        tasks: app(TaskStore::class),
        interrupts: app(InterruptStore::class),
        memory: app(MemoryStore::class),
        traces: app(TraceStore::class),
        locks: $locks,
        delaySchedulers: app(DelaySchedulerResolver::class),
        events: app(RunEventDispatcher::class),
        nodeExecutions: app(NodeExecutionStore::class),
    );
    $manager = new AgentGraphManager($runtime, app(RunEventDispatcher::class));
    $manager->define(
        StateGraph::make('session_lock_graph')
            ->state(['answer' => 'string|null'])
            ->node('ask', TerminalResumeAskNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', StateGraph::END)
            ->compile(),
    );

    $first = $manager->session('session_lock_graph', 'session-lock-thread')->run();
    $second = $manager->session('session_lock_graph', 'session-lock-thread')->run();

    expect($locks->keys)->toContain('agent-graph:session:session_lock_graph:session-lock-thread')
        ->and($second->runId())->toBe($first->runId());
});

final class StrictResumeAskNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->hasResumePayload()) {
            return NodeResult::write([]);
        }

        return NodeResult::interrupt('input', ['prompt' => 'Answer']);
    }
}

final class StrictResumeAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['answer' => (string) $context->state('answer')]);
    }
}

final class SlowNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        usleep(25_000);

        return NodeResult::end(['done' => true]);
    }
}

final class RuntimeOptionsLoopNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['count' => ((int) $context->state('count')) + 1]);
    }
}

final class TerminalResumeAskNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->hasResumePayload()) {
            return NodeResult::end(['answer' => (string) $context->state('answer')]);
        }

        return NodeResult::interrupt('input', ['prompt' => 'Answer']);
    }
}

final class TerminalStateEditNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::interrupt('state_edit', ['title' => 'Edit state']);
    }
}

final class TerminalDoneNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['done' => true]);
    }
}

final class RecordingLockProvider implements LockProvider
{
    public array $keys = [];

    public function withLock(string $key, Closure $callback): mixed
    {
        $this->keys[] = $key;

        return $callback();
    }
}
