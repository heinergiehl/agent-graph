# AgentGraph for Laravel AI SDK

AgentGraph is a Laravel package and runtime SDK for durable AI agent graphs. It complements the official `laravel/ai` package with graph orchestration, checkpoints, resumable runs, interrupts, scoped memory, idempotent tasks, traces, queues, and graph-as-tool integration.

AgentGraph does not replace Laravel AI providers, agents, tools, streaming, or structured output. It uses Laravel AI through public contracts such as `Laravel\Ai\Contracts\Agent` and `Laravel\Ai\Contracts\Tool`.

## Beta Status

`0.12.x` is a public beta intended for sandbox and real chatbot integration testing. Breaking changes are allowed before v1, but they will be documented in `CHANGELOG.md` and `UPGRADE.md`.

The v1 target is a hardened MVP: stable graph execution, checkpoints, interrupts/resume, idempotent tasks, scoped memory, traces, queues, run-event observation, Laravel AI agent nodes, and graphs as tools. Experimental checkpoint inspection, replay, and forking APIs are available for post-v1-style workflows. Deterministic superstep fan-out/fan-in and per-node retry policies are available for LangGraph-style workflows; queue-backed worker parallelism, pgvector semantic memory, OpenTelemetry export, and visual workflow editing are intentionally outside v1.

CI currently validates the 0.12 beta line against PHP 8.3/8.4, Laravel 12/13, and `laravel/ai ^0.7`. `laravel/ai ^1.0` stays declared for forward compatibility but should remain non-blocking until upstream tags a 1.x release.

## Installation

```bash
composer require heiner/agent-graph
php artisan agent-graph:install
php artisan migrate
```

## First Graph

```php
use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

final class ClassifyTicket implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write(['category' => 'billing']);
    }
}

AgentGraph::define(
    StateGraph::make('support_triage')
        ->state([
            'input' => 'string',
            'category' => 'string|null',
            'answer' => 'string|null',
        ])
        ->node('classify', ClassifyTicket::class)
        ->edge(StateGraph::START, 'classify')
        ->edge('classify', StateGraph::END)
);

$run = AgentGraph::graph('support_triage')
    ->thread($conversationId)
    ->input(['input' => $message])
    ->run();
```

## Interrupts

Nodes can pause execution for user input, approval, delay, webhook, manual review, or state edit.

```php
return NodeResult::interrupt('approval', [
    'title' => 'Approve CRM update',
    'summary' => 'The agent wants to update the customer plan.',
]);
```

Resume later:

```php
$run = AgentGraph::resume($runId, [
    'interrupt_id' => $interruptId,
    'approved' => true,
]);
```

The resumed node can read the original resume response separately from merged graph state:

```php
if ($context->hasResumePayload()) {
    $payload = $context->resumePayload();
    $interruptId = $context->interruptId();
}
```

For manual state correction flows, use the explicit state-edit resume API. The patch is validated against the graph state schema before the interrupt is resolved.

```php
$run = AgentGraph::resumeWithStateEdit(
    runId: $runId,
    interruptId: $interruptId,
    statePatch: ['answer' => 'Corrected answer'],
    resolvedBy: (string) $user->id,
);
```

## Runtime Inspection

Inspect a run without resuming it:

```php
$snapshot = AgentGraph::inspect($runId, withHistory: true, withTraces: true);

$snapshot->status();       // completed, interrupted, delayed, failed, cancelled
$snapshot->state();        // latest checkpoint state
$snapshot->checkpoint();   // latest checkpoint
$snapshot->checkpoints();  // populated when withHistory is true
$snapshot->writes();       // persisted checkpoint writes
$snapshot->interrupt();    // current pending interrupt, if any
$snapshot->traces();       // populated when withTraces is true
```

Build a replayable timeline for debuggers or admin UIs:

```php
$timeline = AgentGraph::timeline($runId, includeState: false, includeDiff: true);

foreach ($timeline->steps() as $step) {
    $step->nodeId();
    $step->status();       // completed, interrupted, delayed, failed, skipped
    $step->writes();
    $step->interrupt();
    $step->stateDiff();
}
```

Observe normalized workflow events for a single run:

```php
use Heiner\AgentGraph\Runtime\RunEvent;

$run = AgentGraph::graph('support_triage')
    ->thread($conversationId)
    ->input(['input' => $message])
    ->onEvent(function (RunEvent $event): void {
        logger()->debug('agent-graph.event', $event->toArray());
    })
    ->collectEvents()
    ->run();

$run->events(); // array<RunEvent>
```

Run events are workflow observations such as `run.started`, `node.started`, `stream.delta`, `checkpoint.created`, `interrupt.created`, `run.completed`, and `run.failed`. They are not an HTTP streaming protocol and do not replace Laravel AI token/model streaming.

List recent runs for dashboards, admin screens, or recovery tools:

```php
$interruptedRuns = AgentGraph::runs([
    'status' => 'interrupted',
    'thread_id' => $conversationId,
], limit: 25);
```

List idempotent tasks for inspectors or side-effect debugging:

```php
$tasks = AgentGraph::tasks([
    'run_id' => $runId,
    'status' => 'completed',
], limit: 25);
```

Record generic parent/child run lineage for delegated tools, nested workflows, or inspector UIs:

```php
$child = AgentGraph::graph('support_triage')
    ->thread($conversationId)
    ->input(['input' => $delegatedRequest])
    ->meta(['tenant' => 'acme'])
    ->parent($parentRunId, $parentCheckpointId, 'delegate', relationship: 'tool')
    ->run();

$child->meta()['parent'];
AgentGraph::inspect($child->runId())->parent();
AgentGraph::childRuns($parentRunId, limit: 25);
```

Parent metadata is stored under `run.meta.parent`. It is an inspection convention only; AgentGraph does not yet schedule, cancel, or orchestrate full subgraphs through this API.

## Supersteps and Send

Multiple static or conditional next nodes run as one deterministic superstep. Each node in the same frontier sees the same base state; writes are merged after the whole superstep completes.

```php
use Heiner\AgentGraph\Runtime\Send;

return NodeResult::sendMany([
    Send::to('summarize_item', ['item' => $itemA]),
    Send::to('summarize_item', ['item' => $itemB]),
]);
```

If multiple nodes write the same state channel in one superstep, define an explicit reducer:

```php
StateGraph::make('summaries')
    ->state(['items' => 'array', 'summaries' => 'array'])
    ->reducer('summaries', 'append');
```

`Send` input is node-local and is not persisted into graph state unless the target node writes it. Parallel interrupts are intentionally rejected in the same superstep; route approval or review after fan-in.

## Node Retry Policies

Retry policies are configured per node and apply only to thrown node exceptions. They do not retry `NodeResult::fail()`, interrupts, delays, or schema-validation failures.

```php
StateGraph::make('support')
    ->node('call_api', CallApiNode::class)
    ->edge(StateGraph::START, 'call_api')
    ->edge('call_api', StateGraph::END)
    ->retry('call_api', maxAttempts: 3, delayMs: 100, backoff: 2.0, maxDelayMs: 1000);
```

`maxAttempts` includes the first attempt. Retry attempts emit `node.retrying` Laravel events, traces, and normalized `RunEvent` objects when observation is enabled. Successful retried writes include `runtime.retry` metadata with attempts, max attempts, and failed attempts.

Retries can repeat node side effects. Wrap external API calls, emails, payments, CRM writes, and other irreversible work in `$context->tasks()->once()` with stable task keys before enabling retry policies in production.

## Time Travel

Checkpoint inspection, replay, and forking are exposed as experimental public APIs. They create new runs and never mutate the original run history.

Inspect a specific checkpoint:

```php
$checkpoint = AgentGraph::checkpoint($checkpointId, withWrites: true);

$checkpoint->state();
$checkpoint->stateBefore(); // parent checkpoint state, or null
$checkpoint->stateAfter();  // alias for this checkpoint's state
$checkpoint->nextNodes();
$checkpoint->writes();
```

Replay from a checkpoint:

```php
$replayed = AgentGraph::replay(
    checkpointId: $checkpointId,
    threadId: $conversationId,
    meta: ['reason' => 'support_recheck'],
);
```

Fork from a checkpoint with a reducer-aware state patch:

```php
$forked = AgentGraph::fork(
    checkpointId: $checkpointId,
    statePatch: ['category' => 'technical'],
    asNode: 'classify',
    meta: ['reason' => 'manual_branch'],
);
```

List replay and fork children for a source checkpoint:

```php
$branches = AgentGraph::timeTravelChildren($checkpointId, limit: 25);
```

Replay and fork runs also store `run.meta.parent` with `relationship` set to `replay` or `fork`, so `AgentGraph::childRuns($sourceRunId)` can visualize run-level lineage while `timeTravelChildren()` remains checkpoint-specific.

Replay and fork can execute downstream nodes again. Wrap external side effects such as CRM writes, email, payments, and API calls in idempotent `$context->tasks()->once()` blocks before using time travel in production.

## Laravel AI Agent Node

```php
use Heiner\AgentGraph\LaravelAi\AgentNode;

AgentNode::make('answer')
    ->agent(App\Ai\SupportAgent::class)
    ->prompt(fn (array $state) => $state['input'])
    ->writeTextTo('answer')
    ->writeUsageTo('usage');
```

`AgentNode::stream()` still delegates to Laravel AI's `stream()` API. AgentGraph keeps dispatching `GraphStreamDelta` for streamed text deltas and, when run-event observation is enabled, also exposes those deltas as normalized `stream.delta` `RunEvent` objects. Use `onTextDelta()` for a direct synchronous callback to bridge deltas into app transports:

```php
AgentNode::make('answer')
    ->agent(App\Ai\SupportAgent::class)
    ->prompt(fn (array $state) => $state['input'])
    ->stream()
    ->onTextDelta(fn (string $delta) => broadcast(new AgentDelta($delta)))
    ->writeTextTo('answer');
```

## Graphs as Tools

```php
public function tools(): iterable
{
    return [
        AgentGraph::tool('support_triage')
            ->name('run_support_triage')
            ->description('Run or resume the durable support workflow.')
            ->thread(fn ($request) => $request['thread_id'])
            ->input(fn ($request) => $request['input'] ?? [])
            ->meta(fn ($request) => ['source' => 'laravel-ai-tool']),
    ];
}
```

The tool returns JSON with `status`, `run_id`, `thread_id`, `state`, `interrupt`, and `error`.

Tool responses always include:

```json
{
  "status": "completed",
  "run_id": "run_...",
  "thread_id": "thread-123",
  "state": {},
  "interrupt": null,
  "error": null
}
```

Interrupted runs return a machine-readable `interrupt` payload. Failed runs return `status: "failed"` and an `error` object.

Use `output()` when a parent agent needs a narrower JSON response. Long-running lifecycle observation should use `RunEvent` callbacks instead of GraphTool persistence hooks.

## Stable v1 Public APIs

The 0.12 beta exposes the intended v1-stable API surface documented in [`docs/api-reference.md`](docs/api-reference.md). In short:

- `StateGraph` for fluent graph definitions.
- `Node` and `NodeContext` for runtime node implementation.
- `NodeResult` for writes, gotos, interrupts, completion, and failures.
- `Send` for dynamic fan-out and map/reduce style supersteps.
- `RetryPolicy` and per-node `StateGraph::retry()` configuration for thrown node exceptions.
- `AgentGraph` facade for defining, running, resuming, state-edit resuming, inspecting, listing, cancelling, and exposing tools.
- `RunSnapshot` for read-only runtime inspection.
- `RunTimeline` for ordered checkpoint/write/interrupt/failure timelines with optional state diffs.
- `RunEvent` for optional per-run workflow event observation and collection.
- `CheckpointSnapshot` for read-only checkpoint inspection and experimental time-travel workflows.
- `AgentNode` for Laravel AI agent execution.
- `GraphTool` for Laravel AI tool integration with optional input, output, and run metadata mapping.
- Store contracts for production adapters and tests, including enumerable memory inspection and replaceable delay scheduling.

`checkpoint()`, `replay()`, `fork()`, and `timeTravelChildren()` are public experimental APIs. They are documented and tested, but remain outside the stable v1 core until time-travel workflows have more production mileage.

## Production Checklist

- Run and monitor the published migrations.
- Use database stores as the source of truth.
- Configure queue workers for background and delayed graph continuation.
- Keep trace redaction keys current for your domain.
- Scope memory by tenant or actor before using it in multi-tenant apps.
- Use idempotent task keys for every external side effect.
- Use `tasks()` for side-effect inspection instead of reading package task tables directly.
- Use `inspect()` and `runs()` for recovery/admin UIs instead of reading package tables directly.
- Use `timeline()` for debugger and trace UIs instead of reconstructing checkpoint history manually.
- Use run-event callbacks for lightweight workflow observation; keep token streaming in Laravel AI.
- Use `timeTravelChildren()` to inspect replay/fork lineage for a source checkpoint.
- Use `resumeWithStateEdit()` for manual state correction flows.
- Use per-node retry policies for transient thrown exceptions, and keep side effects idempotent with `tasks()->once()`.
- Use explicit reducers for any state channel that can be written by more than one node in the same superstep.
- Keep graph definitions generic; product-specific UI belongs in consuming apps.
- For multi-tenant memory, always include tenant or actor scope in reads and writes.
- Run `php artisan agent-graph:doctor` after deploys and before release validation.

## Status

This MVP includes the durable runtime core, deterministic supersteps, dynamic `Send` fan-out, per-node retry policies, database and in-memory stores, scoped memory, interrupts, tasks, traces, queue jobs, Laravel AI adapter, graph tool adapter, run-event observation, commands, tests, docs, and experimental checkpoint replay/fork APIs. Post-MVP work includes queue-backed parallel workers, visual timeline tooling, pgvector semantic memory, OpenTelemetry export, and visual editor serialization.
