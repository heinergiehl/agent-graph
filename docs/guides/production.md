# Production

Use database stores as the source of truth. Cache/Redis locks are acceleration and duplicate-execution protection, not durable storage.

Recommended production settings:

- publish and run migrations
- configure queue workers
- define tenant-aware memory scopes
- keep trace redaction keys updated
- prune traces and old runs according to your retention policy
- wrap every external side effect in `$context->tasks()->once()`
- configure `agent-graph.tasks.lease_seconds` longer than expected external side effects
- configure `agent-graph.locks.ttl_seconds` longer than the longest expected node execution
- define reducers for channels written by multiple fan-out branches
- configure per-node retries only for transient thrown exceptions
- avoid storing raw secrets in state, memory, traces, task input, or interrupt payloads
- avoid doing slow network I/O inside run-event listeners

## Database, migrations, and stores

The default store driver is `database`. Keep it for production:

```dotenv
AGENT_GRAPH_STORE=database
```

Use `AGENT_GRAPH_DB_CONNECTION` when AgentGraph tables should live on a dedicated Laravel database connection:

```dotenv
AGENT_GRAPH_DB_CONNECTION=agent_graph
```

Set this before publishing or running migrations. Package migrations, database stores, runtime transactions, `agent-graph:doctor`, `agent-graph:prune`, and the optional `PgvectorMemoryStore` all use the same configured connection. If the env var is unset, AgentGraph uses Laravel's `database.default` connection.

Published migrations create and maintain these package tables:

- `agent_graph_runs`
- `agent_graph_checkpoints`
- `agent_graph_writes`
- `agent_graph_tasks`
- `agent_graph_interrupts`
- `agent_graph_memories`
- `agent_graph_node_executions`
- `agent_graph_traces`

Applications can override table names in `config/agent-graph.php`, but do that before migrating. Do not read these tables directly from application UI code; prefer `AgentGraph::inspect()`, `AgentGraph::runs()`, `AgentGraph::tasks()`, and the memory manager APIs.

Use `AGENT_GRAPH_STORE=memory` only for tests or throwaway local experiments. In-memory stores are process-local and lose all runtime state between requests and workers.

`PgvectorMemoryStore` is optional and experimental. Use it only for semantic memory features such as long-term memory search, similar-case lookup, example selection, or semantic routing. Do not use pgvector for AgentGraph run state, checkpoints, interrupts, queues, task audit, or trace persistence; those remain relational store responsibilities.

## Memory tenancy

In multi-tenant apps, include `tenant` scope on every customer-specific memory write and read. Add `actor` scope for user-specific memory inside a tenant. Reserve `application` or `global` scope for product defaults that contain no customer or user data.

```php
$context->memory()->write(
    scopes: ['tenant' => (string) $tenantId, 'actor' => (string) $userId],
    namespace: 'support.profile',
    key: 'preferences',
    value: ['language' => 'de'],
);
```

## Runtime recovery

Use `AgentGraph::inspect($runId, withHistory: true, withTraces: true)` for admin and recovery screens. It returns the latest state, current checkpoint, checkpoint history, writes, pending interrupt, traces, error, and metadata without changing run state.

Use `AgentGraph::runs($filters, $limit)` to list recent runs by `status`, `thread_id`, `graph_key`, or `graph_version`.

Use `AgentGraph::tasks($filters, $limit)` to inspect idempotent side effects by `run_id`, `node_id`, `checkpoint_id`, or `status`.

Use `onEvent()` or `collectEvents()` when an application needs ordered workflow observations for a single run. Listeners run synchronously in the runtime path, so keep them lightweight and move broadcasting, persistence copies, or expensive processing into application-level jobs.

Run-event observation is not model streaming. Keep Laravel AI as the owner of token streaming and provider behavior; AgentGraph only normalizes workflow events such as run lifecycle, node lifecycle, checkpoints, interrupts, failures, and existing `GraphStreamDelta` payloads.

## Human-in-the-loop state edits

Use `AgentGraph::resumeWithStateEdit($runId, $interruptId, $statePatch, $resolvedBy)` for manual state correction. The runtime validates every patched key against the graph state schema before resolving the pending interrupt, so invalid edits fail without mutating the interrupt.

Normal input and approval resumes should continue to use `AgentGraph::resume($runId, ['interrupt_id' => $interruptId, ...])`.

Terminal runs are immutable for runtime control APIs. `completed`, `cancelled`, and `failed` runs reject `resume()`, `resumeWithStateEdit()`, and `cancel()`; use replay or fork when a workflow needs follow-up work from historical state.

Use `AgentGraph::resumeStrict()` for public endpoints that should reject unknown resume payload keys. If review or approval windows expire, attach `InterruptPolicy` to the interrupt result and call `AgentGraph::expireInterrupts()` from scheduled maintenance.

## Queue and retry safety

Delayed continuation jobs are safe to retry. A delayed job no-ops when the run is already `completed`, `cancelled`, or `failed`, or when its interrupt is no longer the pending delay interrupt.

Delay interrupts schedule through `DelayScheduler::class`. The default implementation dispatches `ContinueDelayedGraphJob` on the configured AgentGraph execution queue connection and queue; bind a custom scheduler only when your app needs a different delayed-execution backend.

Keep external side effects inside `$context->tasks()->once()` so queue retries do not repeat irreversible work.

Task leases prevent duplicate active execution for the same idempotency key. Completed tasks continue to return their stored result, and reusing a key with different input still fails.

`queued_supersteps` is opt-in. Configure `agent-graph.execution.mode=queued_supersteps`, optionally set `agent-graph.execution.queue_connection` and `agent-graph.execution.queue`, and run Laravel workers for that queue. Queued workers must boot the same graph definitions as the process that started or resumed the run.

Equivalent env settings:

```dotenv
AGENT_GRAPH_EXECUTION_MODE=queued_supersteps
AGENT_GRAPH_EXECUTION_QUEUE_CONNECTION=database
AGENT_GRAPH_EXECUTION_QUEUE=agent-graph
AGENT_GRAPH_EXECUTION_NODE_LEASE_SECONDS=300
AGENT_GRAPH_LOCK_TTL_SECONDS=300
```

Keep `AGENT_GRAPH_EXECUTION_MODE=sync` unless graph definitions are registered during app boot and workers are guaranteed to process `NodeExecutionJob` and `ContinueSuperstepJob`.

Set `AGENT_GRAPH_LOCK_TTL_SECONDS` longer than the longest expected node execution or active session start path. A lock expiring too early can allow duplicate protected work while the first PHP process is still running.

## Pruning

`agent-graph:prune` deletes only the targets you explicitly select and uses the same configured database connection as the stores:

```bash
php artisan agent-graph:prune --runs --traces --tasks --memories --days=30 --dry-run
```

- `--runs` deletes completed, failed, or cancelled runs with `updated_at` older than `--days`.
- `--traces` deletes traces with `created_at` older than `--days`.
- `--tasks` deletes completed or failed tasks with `updated_at` older than `--days`.
- `--memories` deletes memories whose `expires_at` is in the past; `--days` does not affect memory expiry.
- `--dry-run` counts matching records without deleting them.

Run pruning from scheduled maintenance according to your product's retention policy.

## Node retry policies

Use `StateGraph::retry($nodeId, maxAttempts: ..., delayMs: ..., backoff: ..., maxDelayMs: ...)` for transient exceptions such as flaky APIs or temporary network failures. `maxAttempts` includes the first attempt.

Node retry policies are synchronous inside the current graph run. They retry only thrown node exceptions. They do not retry `NodeResult::fail()`, human interrupts, delays, or schema-validation failures.

Retrying can execute node code more than once. Keep irreversible side effects inside `$context->tasks()->once()` with stable task keys and deterministic input hashes. Retry attempts are observable through `GraphNodeRetrying`, `node.retrying` traces, and normalized `node.retrying` run events.

## Superstep fan-out

Static multi-edges, conditional fan-out, and dynamic `Send` run deterministically in one process by default. Opt-in `queued_supersteps` mode dispatches each node in a superstep as a `NodeExecutionJob` and aggregates finished executions through `ContinueSuperstepJob` while preserving the same reducer/checkpoint semantics.

In queued mode, `run()` and `resume()` usually return a `running` result after scheduling work. Use `AgentGraph::inspect($runId)` or application notifications to observe the final `completed`, `failed`, `interrupted`, or `delayed` status.

Every node in the same superstep reads the same base state. Writes are merged only after the frontier finishes. Configure an explicit reducer for any channel that can be written by more than one branch.

`Send` input is local to a target node and is preserved in checkpoint metadata for replay/fork. It is not persisted into graph state unless the node writes it. Parallel interrupts inside one frontier are rejected; put approval, review, or state-edit interrupts after fan-in.

## Replay and fork safety

`AgentGraph::replay()` and `AgentGraph::fork()` create new runs from old checkpoint state. They may execute LLM, API, CRM, payment, email, or webhook nodes again.

Before enabling time travel for a graph in production, wrap every irreversible node side effect in `$context->tasks()->once()` with a stable task key and input hash. Use `AgentGraph::timeTravelChildren($checkpointId)` to audit replay and fork branches created from a source checkpoint.

Replay and fork require the persisted checkpoint or run `graph_version` to match the currently registered graph definition. Register a new graph version when node routing or state semantics change.
