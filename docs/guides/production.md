# Production

This guide describes stable **0.16.2**, including durable delay redelivery, the idempotent claim-token migration patch, and the Laravel AI 0.11.2 baseline. Follow the [upgrade guide](../../UPGRADE.md) before using the new store contracts and repeated-scheduling behavior.

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
- keep `agent-graph.locks.fail_without_provider` enabled outside local throwaway tests
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

Run all package migrations after upgrading. The runtime invariant migration adds database constraints for one checkpoint per run step and one node execution per run step schedule slot, plus a run/status interrupt index. AgentGraph enforces the "one pending interrupt per run" rule in the database and in-memory stores instead of relying on a partial unique index, so the same behavior works across SQLite, MySQL, and PostgreSQL.

0.16 adds `2026_08_31_010000_add_claim_token_to_agent_graph_node_executions.php`, which adds nullable `claim_token` to the configured node-execution table. Existing task `attempts` need no migration. During the paused rollout described below, publish missing migrations without `--force`; do not overwrite published migrations or application config:

```bash
php artisan vendor:publish --tag=agent-graph-migrations
php artisan migrate
php artisan agent-graph:doctor
```

Laravel may change a migration's timestamp when publishing it. Confirm the new claim-token migration is present and applied on the configured database connection.

Use `AGENT_GRAPH_STORE=memory` only for tests or throwaway local experiments. In-memory stores are process-local and lose all runtime state between requests and workers.

`PgvectorMemoryStore` is optional and experimental. Use it only for semantic memory features such as long-term memory search, similar-case lookup, example selection, or semantic routing. Do not use pgvector for AgentGraph run state, checkpoints, interrupts, queues, task audit, or trace persistence; those remain relational store responsibilities.

Run `php artisan agent-graph:doctor` before starting upgraded application processes and before release validation. It checks stores, database tables and connection, the node-execution `claim_token` column, cache locks, fail-closed behavior, queues, lease durations, lock TTL, and max steps. Treat `FAIL` output as a rollout blocker until configuration or migrations are corrected.

## Coordinated 0.16 rollout

1. Adapt and verify custom stores and runtime subclasses with the application. `TaskStore` completion/failure now requires the claimed attempt; `NodeExecutionStore` completion/interrupt/failure requires the claimed token. The Filament plugin's `BoundedDatabaseTaskStore` and `BoundedDatabaseNodeExecutionStore` overrides must forward these arguments unchanged.
2. Back up the database, inventory active work, and reconcile unknown remote outcomes before deciding what to retry.
3. Pause new graph work, drain in-flight operations, and stop old web, Octane, queue, Horizon, and scheduler processes. **No mixed 0.15/0.16 PHP execution is safe.** The nullable migration preserves rows; it does not prevent old code from bypassing ownership checks.
4. Deploy the reviewed SDK and adapted application while execution is stopped, then publish missing migrations, migrate, and run doctor as shown above.
5. Start every application entry point on the same new build, verify the host flows, and then reopen normal traffic. A rollback also requires pausing, draining, and reconciling external effects.

Use the [full upgrade checklist](../../UPGRADE.md#coordinated-rollout-checklist) and [Filament plugin upgrade guide](filament-plugin-upgrade-0.16.md). These operational requirements are not a certification of a consuming application's production readiness.

## Graph release validation

Run graph validation in the same application boot path that registers production graph definitions:

```bash
php artisan agent-graph:validate --strict
php artisan agent-graph:validate --strict --json
```

`--strict` fails the command on warnings such as terminal paths, conditionals without default routes, mixed static and conditional outgoing routes, or unreachable nodes. `--json` emits a machine-readable report suitable for CI artifacts and deployment gates.

Review `AgentGraph::manifest($key)->toArray()` before exposing a graph to users or parent agents. Manifest v2 includes exact state schemas, reducers, routing, retry/timeout/concurrency policies, and neutral node metadata such as input/output channels, interrupt capability, and side-effect categories. Keep product UI classes out of core graph definitions; consuming apps can project manifest metadata into their own admin or workflow screens.

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

Use `AgentGraph::recover($runId)` when a `running` run lost its process after an accepted resume/state-edit transition, after a durable checkpoint, or after queued execution records were committed but node/continuation dispatch was lost. In 0.16 this includes an initial persisted queue frontier before the first checkpoint. Recovery acquires the run lock, preserves graph-version checks, and returns waiting or terminal runs without mutation.

Inconsistent legacy wait state requires reconciliation unless durable records prove that the matching resume was accepted. Inspect checkpoints, interrupts, accepted payloads, and queue records; do not invent approval or remove safety metadata to continue. Without a checkpoint or durable queue frontier, recovery refuses to restart arbitrary input.

Database-backed resume acceptance is one transaction across the pending interrupt compare-and-set, `running` status/options, and a bounded recovery marker. The marker is cleared atomically at the next checkpoint or queued-frontier persistence boundary. In 0.16, each waiting superstep also commits its checkpoint, writes, interrupt, and waiting status together, before observer notification.

Cancel resolves a pending interrupt and writes the terminal run status in one transaction. Late queued-worker failure cannot overwrite cancellation. Cancellation applies to that run only; it does not cascade through child runs or undo an external call already in flight.

AgentGraph does not guarantee exactly-once external execution. In sync mode, a process exit before the next checkpoint can cause the current frontier to run again. Put external side effects inside `$context->tasks()->once()` with stable keys, use provider idempotency where available, and reconcile unknown remote outcomes instead of blindly retrying them.

Use `onEvent()` or `collectEvents()` when an application needs ordered workflow observations for a single run. Listeners run synchronously in the runtime path, so keep them lightweight and move broadcasting, persistence copies, or expensive processing into application-level jobs.

Run-event observation is not model streaming. Keep Laravel AI as the owner of token streaming and provider behavior; AgentGraph only normalizes workflow events such as run lifecycle, node lifecycle, checkpoints, interrupts, failures, and existing `GraphStreamDelta` payloads. In 0.16, a terminal streaming `Error` raises `AgentStreamException` and follows node failure/retry handling. Do not treat text deltas already delivered to a client as a successful final state.

## Human-in-the-loop state edits

Use `AgentGraph::resumeWithStateEdit($runId, $interruptId, $statePatch, $resolvedBy)` for manual state correction. The runtime validates every patched key against the graph state schema before resolving the pending interrupt, so invalid edits fail without mutating the interrupt.

Normal input and approval resumes should continue to use `AgentGraph::resume($runId, ['interrupt_id' => $interruptId, ...])`. Use `AgentGraph::resumeContract()` for public endpoints that answer typed `InterruptContract` slot, approval, or choice waitpoints and should validate the response before resolving the interrupt.

Terminal runs are immutable for runtime control APIs. `completed`, `cancelled`, and `failed` runs reject `resume()`, `resumeWithStateEdit()`, and `cancel()`; use replay or fork when a workflow needs follow-up work from historical state.

Use `AgentGraph::resumeStrict()` for public endpoints that should reject unknown resume payload keys. If review or approval windows expire, attach `InterruptPolicy` to the interrupt result and call `AgentGraph::expireInterrupts()` from scheduled maintenance.

## Queue and retry safety

Delayed continuation jobs are safe to retry. A delayed job no-ops when the run is already `completed`, `cancelled`, or `failed`, or when its interrupt is no longer the pending delay interrupt.

Delay interrupts schedule through `DelayScheduler::class`. The default implementation dispatches `ContinueDelayedGraphJob` on the configured AgentGraph execution queue connection and queue; bind a custom scheduler only when your app needs a different delayed-execution backend. The runtime resolves the scheduler lazily, so package or app service providers can rebind the contract after the runtime has already been constructed.

In 0.16.0, the delay record commits before its queue push. If that push or an acknowledged job is lost, `recover()` can request delivery again through the bound `DelayScheduler` with the original interrupt identity and absolute due time. It leaves the wait and its checkpoint unchanged. Custom schedulers must accept repeated scheduling without changing delivery authority, and asynchronous workers must honor the due time. No stranded-run scanner is added; the host must invoke recovery. Legacy 0.15.1 delay checkpoints without the required `runtime.wait` marker need explicit reconciliation. See [delay recovery](delay-recovery.md) for validation, duplicate delivery, and transport limits.

Keep external side effects inside `$context->tasks()->once()` with stable operation keys. Receipt reuse prevents repeating completed work for that key and input; it cannot resolve an unknown remote outcome by itself.

0.16 task claims atomically reject unexpired leases and conflicting input, or return an existing completed receipt. New claims increment `attempts`, and only the matching running attempt may complete or fail. A late writer raises `TaskClaimLostException` rather than overwriting the current owner. A throwing `GraphTaskCompleted` listener can still fail the caller, but cannot downgrade the completed task. See [idempotent tasks](../concepts/idempotent-tasks.md) for lease expiry, key scope, and provider idempotency.

Queued node executions persist each node's result for `queued_supersteps`. Every frontier is stored in one database transaction before dispatch. Recovery redrives a valid pending/running frontier or its continuation aggregator. A persisted failed peer instead fails the run without starting new peers; already-running work cannot be undone.

Each running claim receives a new `claim_token`. Completion, interruption, or failure must match that token and running status; stale final writes raise `NodeExecutionClaimLostException`. A worker retry receives an existing terminal record unchanged, so completed siblings are not rerun. Failure to dispatch continuation does not downgrade a stored successful result. Successful aggregation creates one checkpoint for the finished frontier.

`queued_supersteps` is opt-in. Configure `agent-graph.execution.mode=queued_supersteps`, optionally set `agent-graph.execution.queue_connection` and `agent-graph.execution.queue`, and run Laravel workers for that queue. Queued workers must boot the same graph definitions as the process that started or resumed the run.

Equivalent env settings:

```dotenv
AGENT_GRAPH_EXECUTION_MODE=queued_supersteps
AGENT_GRAPH_EXECUTION_QUEUE_CONNECTION=database
AGENT_GRAPH_EXECUTION_QUEUE=agent-graph
AGENT_GRAPH_EXECUTION_NODE_LEASE_SECONDS=300
AGENT_GRAPH_JOB_TRIES=3
AGENT_GRAPH_JOB_TIMEOUT=300
AGENT_GRAPH_JOB_BACKOFF=5
AGENT_GRAPH_LOCK_TTL_SECONDS=300
```

AgentGraph queue jobs apply `AGENT_GRAPH_JOB_TRIES`, `AGENT_GRAPH_JOB_TIMEOUT`, and comma-separated `AGENT_GRAPH_JOB_BACKOFF` values uniformly across run, resume, delayed resume, node execution, and continuation jobs. Jobs also include `agent-graph` tags plus operation-specific run, graph, thread, execution, or step identifiers for queue dashboards and worker telemetry.

Resume, state-edit resume, recovery, cancel, queued continuation, and delayed continuation paths all acquire the run lock before mutating runtime state. Keep lock TTLs longer than the longest expected node execution so those guards remain effective.

Keep `AGENT_GRAPH_EXECUTION_MODE=sync` unless graph definitions are registered during app boot and workers are guaranteed to process `NodeExecutionJob` and `ContinueSuperstepJob`.

Set `AGENT_GRAPH_LOCK_TTL_SECONDS` longer than the longest expected node execution or active session start path. A lock expiring too early can allow duplicate protected work while the first PHP process is still running.

Production runs require a cache store that supports atomic locks. Keep `AGENT_GRAPH_LOCK_FAIL_WITHOUT_PROVIDER=true` outside local throwaway tests so missing lock support fails clearly instead of allowing duplicate runs, resumes, or side effects.

## Subgraph and tool boundaries

0.16 checks a graph tool's graph and any configured or supplied thread before resume. A custom `thread()` resolver runs on resume as well as start; derive its identity from trusted application context. Durable sessions always require their configured graph and thread. Keep application authentication and tenant authorization in place.

For a pending subgraph interrupt, forward the exact bubbled `child_run_id` and `child_interrupt_id`. Identity and child-state validation happen before accepting the parent response. Embedded child definitions are registered recursively when the parent is defined. A delayed child stays waiting; a non-completed child is never mapped as successful output.

The SDK does not coordinate asynchronous child completion or cascade cancellation through a run tree. Retain the consuming application's coordinators, including the Filament plugin's structured-concurrency wrappers. See the [plugin upgrade guide](filament-plugin-upgrade-0.16.md).

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

Sync mode persists only completed superstep checkpoints. If a PHP process dies inside a sync superstep, the current frontier can need to run again. Use `queued_supersteps` when worker-backed recovery needs to preserve completed sibling node work across process failures or queue retries.

`Send` input is local to a target node and is preserved in checkpoint metadata for replay/fork. It is not persisted into graph state unless the node writes it. Parallel interrupts inside one frontier are rejected; put approval, review, or state-edit interrupts after fan-in.

## Replay and fork safety

`AgentGraph::replay()` and `AgentGraph::fork()` create new runs from old checkpoint state. They may execute LLM, API, CRM, payment, email, or webhook nodes again.

Before enabling time travel, define whether an effect is new work or the same business operation. A task key containing the new run ID intentionally differs from the original and can execute again. To reuse a completed receipt across runs, preserve a stable operation key and the same input hash; use provider idempotency and reconcile unknown outcomes before retrying. Use `AgentGraph::timeTravelChildren($checkpointId)` to audit replay and fork branches.

Replay and fork require the persisted checkpoint or run `graph_version` to match the currently registered graph definition. Register a new graph version when node routing or state semantics change.
