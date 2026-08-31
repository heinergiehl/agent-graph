# AgentGraph for Laravel AI SDK

AgentGraph is a Laravel package and runtime SDK for durable AI agent graphs. It complements the official `laravel/ai` package with graph orchestration, checkpoints, resumable runs, interrupts, scoped memory, idempotent tasks, traces, queues, and graph-as-tool integration.

AgentGraph does not replace Laravel AI providers, agents, tools, streaming, or structured output. It uses Laravel AI through public contracts such as `Laravel\Ai\Contracts\Agent` and `Laravel\Ai\Contracts\Tool`.

## Release Status

`0.15.1` is the current published stable pre-v1 version. `main` is being prepared for **0.16.0 (unreleased)**, with runtime hardening and breaking persistence-store signatures. The 0.16 notes below describe development code, not a published release. Ordinary graph/run/resume and `TaskRunner::once()` method signatures remain unchanged; custom stores need a coordinated upgrade. Read the [changelog](CHANGELOG.md) and [upgrade guide](UPGRADE.md) before adopting it.

The v1 target is a hardened MVP: stable graph execution, checkpoints, interrupts/resume, idempotent tasks, scoped memory, traces, queues, run-event observation, Laravel AI agent nodes, graphs as tools, native subgraph nodes, and durable app workflow sessions. Experimental checkpoint inspection, replay, forking, worker-backed queued supersteps, and vector memory contracts are available for post-v1-style workflows. OpenTelemetry export and visual workflow editing remain outside the stable v1 core.

CI validates the pre-v1 release line against PHP 8.3/8.4, Laravel 12/13, and `laravel/ai ^0.7`. `laravel/ai ^1.0` stays declared for forward compatibility but should remain non-blocking until upstream tags a 1.x release.

## Installation

```bash
composer require heiner/agent-graph:^0.15.1
php artisan agent-graph:install
php artisan migrate
```

The `^0.15.1` constraint stays on the stable 0.15 line and will not install 0.16 automatically. The prepared 0.16 build requires adapted custom stores, an additive migration, and a coordinated restart of all application processes; see the [upgrade checklist](UPGRADE.md#coordinated-rollout-checklist).

`agent-graph:install` publishes the package config and migrations. The database store uses these tables by default:

- `agent_graph_runs`
- `agent_graph_checkpoints`
- `agent_graph_writes`
- `agent_graph_tasks`
- `agent_graph_interrupts`
- `agent_graph_memories`
- `agent_graph_node_executions`
- `agent_graph_traces`

Set `AGENT_GRAPH_DB_CONNECTION` when AgentGraph tables should live on a non-default Laravel database connection. Migrations, database stores, `agent-graph:doctor`, `agent-graph:prune`, runtime transactions, and the optional `PgvectorMemoryStore` all use the configured connection. Leave it unset to use `database.default`.

Useful production env settings:

```dotenv
AGENT_GRAPH_STORE=database
AGENT_GRAPH_DB_CONNECTION=agent_graph
AGENT_GRAPH_EXECUTION_MODE=sync
AGENT_GRAPH_EXECUTION_QUEUE_CONNECTION=database
AGENT_GRAPH_EXECUTION_QUEUE=agent-graph
AGENT_GRAPH_TASK_LEASE_SECONDS=300
AGENT_GRAPH_EXECUTION_NODE_LEASE_SECONDS=300
AGENT_GRAPH_LOCK_TTL_SECONDS=300
AGENT_GRAPH_LOCK_FAIL_WITHOUT_PROVIDER=true
```

Use `AGENT_GRAPH_STORE=memory` only for tests and local throwaway runs. Use `AGENT_GRAPH_EXECUTION_MODE=queued_supersteps` only when workers boot the same graph definitions and process the configured queue.

Set `AGENT_GRAPH_LOCK_TTL_SECONDS` longer than the longest expected node execution or active session start path. The default is 300 seconds.

Production runs require a cache store that supports atomic locks. Keep `AGENT_GRAPH_LOCK_FAIL_WITHOUT_PROVIDER=true` outside local throwaway tests.

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

For machine-readable waitpoints, use typed interrupt contracts. They keep the existing interrupt runtime behavior while giving apps, inspectors, and chat projections a stable payload shape.

```php
use Heiner\AgentGraph\Graph\InterruptContract;

return NodeResult::interruptContract(
    InterruptContract::slotValue(
        nodeId: 'collect_email',
        question: 'Which email should receive the follow-up?',
        slot: 'email',
        inputType: 'email',
    )
);
```

Resume later:

```php
$run = AgentGraph::resume($runId, [
    'interrupt_id' => $interruptId,
    'approved' => true,
]);
```

For public endpoints that answer typed slot, approval, or choice contracts, use contract-aware resume validation:

```php
$run = AgentGraph::resumeContract($runId, [
    'interrupt_id' => $interruptId,
    'answer_type' => 'approve',
]);
```

Only active runs can be resumed. `completed`, `cancelled`, and `failed` runs reject `resume()` and `resumeWithStateEdit()` so terminal history is not mutated accidentally.

Resume acceptance is durable before continuation starts. If a process exits after the interrupt was accepted but before the next checkpoint or queued frontier was persisted, retry the exact same resume payload or call `AgentGraph::recover($runId)`. A different payload cannot take over the accepted transition.

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

Recover a `running` run from its accepted resume transition or latest durable checkpoint:

```php
$run = AgentGraph::recover($runId);
```

In 0.16, database-backed supersteps commit the checkpoint, writes, interrupt, and waiting status before notifying observers. Recovery also redrives an initial persisted queued frontier before the first checkpoint. Inconsistent legacy wait state requires reconciliation unless durable records prove the matching resume was accepted.

Recovery is a no-op for runs that are still `interrupted` or `delayed` with a pending interrupt, and for terminal runs. It does not repair a lost delay-queue push; inspect and re-enqueue the existing delay through the application's scheduler. In sync mode a frontier that did not reach its next checkpoint can run again. Use `$context->tasks()->once()` with stable operation keys and provider idempotency where available, and reconcile unknown external outcomes before retrying.

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

Parent metadata is stored under `run.meta.parent`. Manual child runs and native `SubgraphNode` child runs use the same lineage shape so inspector UIs can show delegated work consistently.

## Graph Manifests and Validation

Compiled graphs expose a read-only manifest for release checks, tooling, inspectors, and visual editors:

```php
$manifest = AgentGraph::manifest('support_triage')->toArray();

$manifest['manifest_version']; // 2
$manifest['state'];       // normalized state channel definitions
$manifest['nodes'];       // node ids, metadata, channels, interrupt and side-effect contracts
$manifest['edges'];       // static routing
$manifest['conditionals']; // conditional route metadata
$manifest['policies'];    // retry, timeout, concurrency metadata
```

Populate node contract metadata without adding UI-specific classes:

```php
StateGraph::make('support_triage')
    ->node('answer', AnswerNode::class)
    ->nodeMeta('answer', ['label' => 'Answer', 'type' => 'agent'])
    ->nodeChannels('answer', input: ['input'], output: ['answer'])
    ->nodeCanInterrupt('answer')
    ->nodeSideEffects('answer', ['read', 'write']);
```

Validate registered graph definitions before release:

```bash
php artisan agent-graph:validate
php artisan agent-graph:validate support_triage
php artisan agent-graph:validate --strict --json
php artisan agent-graph:validate --allow-empty
```

Validation reports unknown state schema types, unknown reducers, unreachable nodes, terminal paths, conditionals without default routes, and mixed static plus conditional outgoing routes without mutating runtime state. The command fails when no graph definitions are registered, so CI does not pass accidentally because the host app skipped graph bootstrapping. Use `--strict` when warnings should fail CI, `--json` for machine-readable reports, and `--allow-empty` only for packages or environments where an empty registry is intentional.

## Supersteps and Send

Multiple `StateGraph::START` edges execute as the first superstep. Multiple static or conditional next nodes run as one deterministic superstep. Each node in the same frontier sees the same base state; writes are merged after the whole superstep completes.

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

Reducer names are strict. Unknown strings throw during reducer normalization instead of silently falling back to last-write-wins.

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

## Runtime Hardening

Per-node timeout and concurrency policies are additive to retry policies:

```php
StateGraph::make('support')
    ->node('call_api', CallApiNode::class)
    ->timeout('call_api', seconds: 10)
    ->concurrency('call_api', limit: 1, key: 'support-api');
```

Timeouts are portable wall-clock checks after node execution returns. Concurrency uses AgentGraph's lock provider and does not change Laravel AI provider, queue, or streaming behavior. The built-in runtime currently supports exclusive node concurrency only: `limit` must be `1`.

In 0.16, task claims are atomic. Completed keys return their stored result, conflicting input is rejected, and unexpired leases block another claim. Each claim has a monotonically increasing attempt; late completion or failure cannot overwrite a replacement claim. A throwing `GraphTaskCompleted` listener leaves the completed receipt intact. These checks protect stored results, not exactly-once execution of remote effects; see [idempotent tasks](docs/concepts/idempotent-tasks.md).

For stricter human-resume flows, use `resumeStrict()`:

```php
$run = AgentGraph::resumeStrict($runId, [
    'interrupt_id' => $interruptId,
    'approved' => true,
]);
```

Normal `resume()` remains permissive for unknown payload keys while still validating known state channels.

State schemas are strict about schema definitions. Unknown primitive types such as `strng`, unknown union members, and unknown structured types throw during validation. Structured arrays declared with `StateSchema::array('ids', 'string')` require a PHP list, and every item is validated against the item schema.

`cancel()` applies only to active runs: `running`, `interrupted`, or `delayed`. Terminal runs remain unchanged. When a pending interrupt exists, cancel resolves it with a typed `cancelled` response in the same database transaction as the terminal run transition. Resume, state-edit resume, recovery, cancel, queued continuation, and delayed continuation paths are protected by run locks.

Interrupts can carry expiry policy metadata:

```php
use Heiner\AgentGraph\Graph\InterruptPolicy;

return NodeResult::interrupt('approval', ['prompt' => 'Approve?'])
    ->withInterruptPolicy(InterruptPolicy::expiresAfter(600));

AgentGraph::expireInterrupts();
```

## Subgraphs

Use `SubgraphNode` to run a registered graph as a durable child run. Child runs are normal AgentGraph runs with `run.meta.parent` lineage.

```php
use Heiner\AgentGraph\Runtime\SubgraphNode;

StateGraph::make('parent')
    ->state(['message' => 'string', 'answer' => 'string'])
    ->node('delegate', SubgraphNode::make('delegate', 'child_graph')
        ->mapped(
            input: fn (array $state) => ['child_input' => $state['message']],
            output: fn (array $childState) => ['answer' => $childState['child_answer']],
        ))
    ->edge(StateGraph::START, 'delegate');
```

Supported modes are `isolated()`, `shared()`, and `mapped()`. Child interrupts bubble as parent `subgraph` interrupts; resume the parent with the current bubbled `child_run_id` and `child_interrupt_id`. In 0.16, missing, foreign, or stale child identities and invalid child state are rejected before parent acceptance. Delayed children remain waiting, and only completed children supply successful output. Parallel interrupt restrictions still apply.

Passing an embedded `GraphDefinition` to `SubgraphNode::make()` registers its child definitions recursively when the parent is defined. The SDK does not provide asynchronous child orchestration or cancellation cascading. Keep application coordinators and the Filament plugin's structured-concurrency wrappers; see the [plugin upgrade guide](docs/guides/filament-plugin-upgrade-0.16.md).

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

Replay and fork can execute downstream nodes again. They create new run IDs, so a task key containing `$context->runId()` also changes and may intentionally execute new work. To deduplicate the same business operation across runs, preserve its operation key and input hash, use provider idempotency where supported, and reconcile unknown results. See [task key scope](docs/concepts/idempotent-tasks.md#retries-replay-and-fork).

## Laravel AI Agent Node

```php
use Heiner\AgentGraph\LaravelAi\AgentNode;

AgentNode::make('answer')
    ->agent(App\Ai\SupportAgent::class)
    ->prompt(fn (array $state) => $state['input'])
    ->writeTextTo('answer')
    ->writeUsageTo('usage');
```

`AgentNode::stream()` delegates to Laravel AI's `stream()` API. In 0.16, a terminal Laravel AI streaming `Error` raises `AgentStreamException` instead of committing partial output as success. Already delivered deltas can remain visible, so consumers must check the final outcome.

AgentGraph dispatches `GraphStreamDelta` for streamed text deltas and, when run-event observation is enabled, exposes those deltas as normalized `stream.delta` `RunEvent` objects. Use `onTextDelta()` for a direct synchronous callback to bridge deltas into app transports:

```php
AgentNode::make('answer')
    ->agent(App\Ai\SupportAgent::class)
    ->prompt(fn (array $state) => $state['input'])
    ->stream()
    ->onTextDelta(fn (string $delta) => broadcast(new AgentDelta($delta)))
    ->writeTextTo('answer');
```

`AgentNode` can also copy public Laravel AI response metadata into graph state without touching provider internals:

```php
AgentNode::make('answer')
    ->agent(App\Ai\SupportAgent::class)
    ->prompt(fn (array $state) => $state['input'])
    ->writeTextTo('answer')
    ->writeStructuredTo('structured')
    ->writeToolCallsTo('tool_calls')
    ->writeToolResultsTo('tool_results')
    ->writeStepsTo('steps')
    ->writeStreamEventsTo('stream_events');
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
            ->schemaInput(fn ($schema) => $schema->object([
                'message' => $schema->string()->required(),
            ]))
            ->meta(fn ($request) => ['source' => 'laravel-ai-tool']),
    ];
}
```

The tool returns JSON with `status`, `run_id`, `thread_id`, `state`, `interrupt`, and `error`.

In 0.16, `GraphTool` rejects resume targets from a different graph or a different configured/supplied thread. Its `thread()` resolver is evaluated on resume too. `DurableGraphSession` requires the run to match its graph and thread. Derive thread identity from trusted application context and retain tenant authorization at the application boundary.

When the graph is registered before Laravel AI asks for the tool schema, `GraphTool` derives optional `input` properties from graph state. Prefer `schemaInput()` for public tool contracts so internal state channels do not become the parent agent's input surface. Laravel's current JSON schema factory does not express arbitrary union schemas, so union state channels are described conservatively in the tool schema while the exact state contract remains available through `GraphManifest`.

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

For active-thread app workflows, use a durable session or durable tool instead of changing `GraphTool`:

```php
$run = AgentGraph::session('support_triage', $conversationId)
    ->run(['input' => $message]);

$status = AgentGraph::session('support_triage', $conversationId)->status();
```

`AgentGraph::graph(...)->thread(...)->run()` intentionally creates a new run. `AgentGraph::session(...)->run()` is active-thread idempotent: it returns the existing `running`, `interrupted`, or `delayed` run for the graph+thread when one exists, and that check/start path is protected by an AgentGraph session lock. Use `session()->start()` when you explicitly want a fresh run.

```php
AgentGraph::durableTool('support_triage')
    ->description('Start, inspect, resume, or cancel the active support workflow.');
```

`DurableGraphTool` returns JSON with `status`, `run_id`, `thread_id`, `state`, `interrupt`, `summary`, and `error`.

## Memory Manager

`AgentGraph::memory()` wraps the configured memory store with extractor and privacy helpers:

```php
use Heiner\AgentGraph\Memory\MemoryScope;

$scope = MemoryScope::thread($conversationId, tenantId: $tenantId);

AgentGraph::memory()->writeExtracted($scope, 'profile', $text, ['source' => 'chat']);
AgentGraph::memory()->export($scope, 'profile');
AgentGraph::memory()->deleteNamespace($scope, 'profile');
```

Vector memory is contract-based and optional. Laravel AI can provide embeddings; AgentGraph stores vectors only when an application binds a vector store. The default bindings are deterministic/in-memory test-safe adapters. `PgvectorMemoryStore` and `stubs/pgvector-memory-migration.stub` are optional experimental starting points for semantic memory on PostgreSQL pgvector, not core persistence for runs, checkpoints, interrupts, queues, or audit logs.

## Public APIs

The graph-building and runtime surface remains available in 0.16. Persistence adapter signatures change before v1; review [UPGRADE.md](UPGRADE.md) alongside the [API reference](docs/api-reference.md):

- `StateGraph` for fluent graph definitions.
- `Node` and `NodeContext` for runtime node implementation.
- `NodeResult` for writes, gotos, interrupts, completion, and failures.
- `Send` for dynamic fan-out and map/reduce style supersteps.
- `RetryPolicy` and per-node `StateGraph::retry()` configuration for thrown node exceptions.
- `TimeoutPolicy`, `ConcurrencyPolicy`, and per-node `StateGraph::timeout()` / `StateGraph::concurrency()` configuration.
- `AgentGraph` facade for defining, running, resuming, state-edit resuming, inspecting, listing, cancelling, and exposing tools.
- `GraphSchemaExporter`, `GraphManifest`, and `GraphValidator` for read-only graph contracts, exact state schemas, and release-readiness checks.
- `InterruptContract` for typed human-in-the-loop waitpoint payloads.
- `RunSnapshot` for read-only runtime inspection.
- `RunTimeline` for ordered checkpoint/write/interrupt/failure timelines with optional state diffs.
- `RunEvent` for optional per-run workflow event observation and collection.
- `CheckpointSnapshot` for read-only checkpoint inspection and experimental time-travel workflows.
- `AgentNode` for Laravel AI agent execution.
- `GraphTool` for Laravel AI tool integration with optional input, output, and run metadata mapping.
- `DurableGraphSession` and `DurableGraphTool` for active-thread app workflows.
- `SubgraphNode` for native child graph execution with parent/child lineage.
- Store contracts for production adapters and tests, including enumerable memory inspection and replaceable delay scheduling.

`checkpoint()`, `replay()`, `fork()`, and `timeTravelChildren()` are public experimental APIs. They are documented and tested, but remain outside the stable v1 core until time-travel workflows have more production mileage.

## Production Checklist

- For 0.16, follow the [coordinated upgrade](UPGRADE.md#coordinated-rollout-checklist): adapt stores, back up, drain all old PHP processes, publish missing migrations without `--force`, migrate, and run doctor before restarting. Do not mix 0.15 and 0.16 processes.
- Run and monitor the published migrations.
- Use database stores as the source of truth.
- Set `AGENT_GRAPH_DB_CONNECTION` before migrating when AgentGraph should use a dedicated connection.
- Configure queue workers for background and delayed graph continuation.
- Keep `execution.mode=sync` unless a graph is registered during app boot and workers can process `NodeExecutionJob` / `ContinueSuperstepJob`.
- Keep cache locks fail-closed outside local throwaway tests.
- Queue jobs use package-level tries, timeout, backoff, and AgentGraph tags for worker telemetry.
- Run `php artisan agent-graph:prune --dry-run --traces --tasks --memories` before enabling retention deletes.
- Keep trace redaction keys current for your domain.
- Scope memory by tenant or actor before using it in multi-tenant apps.
- Use stable task keys and provider idempotency for external effects; reconcile unknown outcomes before retrying.
- Use `tasks()` for side-effect inspection instead of reading package task tables directly.
- Use `inspect()` and `runs()` for recovery/admin UIs instead of reading package tables directly.
- Use `timeline()` for debugger and trace UIs instead of reconstructing checkpoint history manually.
- Use run-event callbacks for lightweight workflow observation; keep token streaming in Laravel AI.
- Use `timeTravelChildren()` to inspect replay/fork lineage for a source checkpoint.
- Use `resumeWithStateEdit()` for manual state correction flows.
- Use per-node retry policies for transient thrown exceptions, and keep side effects idempotent with `tasks()->once()`.
- Configure `agent-graph.tasks.lease_seconds` for the maximum expected side-effect duration.
- Configure `agent-graph.locks.ttl_seconds` longer than the longest expected node execution.
- Use `resumeStrict()` for public endpoints that should reject unknown resume payload keys.
- Use `resumeContract()` for public endpoints that answer typed interrupt contracts.
- Treat terminal runs as immutable for resume/state-edit/cancel flows; use replay or fork for follow-up work from historical state.
- Keep `GraphTool` generic; use `schemaInput()` for bounded public tool input and `DurableGraphTool` for active-run-per-thread application semantics.
- Use explicit reducers for any state channel that can be written by more than one node in the same superstep.
- Keep graph definitions generic; product-specific UI belongs in consuming apps.
- For multi-tenant memory, always include tenant or actor scope in reads and writes.
- Run `php artisan agent-graph:doctor` before restarting after an upgrade and before release validation. Treat `FAIL` lines as blockers; in 0.16 it also checks the node-execution `claim_token` column, alongside tables, locks, stores, queues, lease timing, and max-step bounds.
- Run `php artisan agent-graph:validate --strict` in host apps that register production graph definitions during boot.

## Status

This MVP includes the durable runtime core, production-grade graph contracts, deterministic supersteps, dynamic `Send` fan-out, per-node retry/timeout/concurrency policies, database and in-memory stores, scoped memory, interrupts with expiry and typed response validation, task leases, traces, queue jobs, worker-backed queued supersteps, Laravel AI adapter, graph tool adapters, subgraph nodes, run-event observation, commands, tests, docs, optional experimental vector-memory adapters, and experimental checkpoint replay/fork APIs. Post-MVP work includes visual timeline tooling, production-grade pgvector CI/adapter hardening, OpenTelemetry export, and visual editor serialization.
