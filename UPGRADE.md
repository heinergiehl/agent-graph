# Upgrade Guide

## 0.15.1 To 0.16.0 (Unreleased)

0.16.0 is being prepared; 0.15.1 remains the published stable version. This upgrade changes persistence adapter contracts and rejects unsafe resume requests. Ordinary `TaskRunner::once()` and public graph/run/resume method signatures remain unchanged.

### Migration map

| Boundary | Required change |
| --- | --- |
| `TaskStore` implementations and callers | Pass the claimed `int $attempt` as the second argument to `complete()` and `fail()`; claim and finish atomically. |
| `NodeExecutionStore` implementations and callers | Pass the claimed `string $claimToken` as the second argument to `complete()`, `interrupt()`, and `fail()`. |
| Database schema | Publish and run the additive nullable `claim_token` migration. Existing task `attempts` need no migration. |
| Runtime subclasses | Replace overrides of removed `persistQueuedInterrupt()`; review `persistSuperstepCheckpoint()` and `notifySuperstepCommitted()`. |
| Persistence subclasses | Node-store `updateResult()` takes `$claimToken` as its second argument. `DatabaseTaskStore::isDuplicateKeyException()` was removed; duplicate insertion uses Laravel's typed exception after transaction rollback. |
| Tool, session, and subgraph resumes | Preserve trusted graph/thread scope and the current parent/child interrupt identities; handle validation errors without inventing replacement IDs. |
| Streaming consumers | Treat `AgentStreamException` as failure; previously delivered text deltas are not a successful final result. |

The exact store signatures are:

```php
// Heiner\AgentGraph\Contracts\TaskStore
public function complete(string $key, int $attempt, mixed $result): array;
public function fail(string $key, int $attempt, string $message, array $meta = []): array;

// Heiner\AgentGraph\Contracts\NodeExecutionStore
public function complete(string $executionId, string $claimToken, array $result): array;
public function interrupt(string $executionId, string $claimToken, array $result): array;
public function fail(string $executionId, string $claimToken, array $error): array;
```

For tasks, `start()` must atomically return an existing completed record or acquire an available attempt. It must reject conflicting input and unexpired running leases. Increment `attempts` on each new claim, and accept completion or failure only while that exact attempt is still running. Use the attempt returned by `start()`, not a later lookup.

Task claim context supports `run_id`, `checkpoint_id`, `node_id`, and `meta`. The in-memory store no longer merges arbitrary context fields over status, attempts, or other ownership fields.

For queued nodes, every successful running `claim()` must issue a new non-empty `claim_token`. Completing, interrupting, or failing requires the matching running token. Returning an existing terminal execution does not grant a new claim.

Stale final writes raise `Heiner\AgentGraph\Exceptions\TaskClaimLostException` or `Heiner\AgentGraph\Exceptions\NodeExecutionClaimLostException`. Inspect the authoritative record; do not bypass ownership checks, borrow the latest attempt/token, or retry an external effect blindly.

Update all overrides and decorators, not just direct interface implementations. In the Filament Agentic Chatbot plugin, `BoundedDatabaseTaskStore` and `BoundedDatabaseNodeExecutionStore` need the new signatures and must forward ownership unchanged. Their payload bounds remain necessary. See the [Filament plugin upgrade guide](docs/guides/filament-plugin-upgrade-0.16.md) for the consuming-package changes.

### Behavior changes to verify

Database-backed checkpoints, writes, new interrupts, and waiting run status now commit together. Observer failure cannot leave a completed wait checkpoint without its interrupt. A `GraphTaskCompleted` listener can still throw, but the completed task receipt remains stored. A node result also remains durable if dispatching its continuation fails.

Recovery can redrive a persisted initial queued frontier without a first checkpoint. A persisted peer failure stops new peer work; late worker failure cannot turn a cancelled run into failed. Already-running external calls cannot be revoked by these guards.

Recovery rejects inconsistent older wait state unless persisted records prove the matching resume was accepted. Review checkpoint, interrupt, resume, and queue records before reconciling such runs. Do not synthesize approval or clear safety metadata merely to make recovery continue. A run without a checkpoint or durable queued frontier is not restarted from arbitrary input.

`GraphTool` checks graph identity and checks thread identity when a thread is supplied or configured. Its `thread()` resolver now runs on resume too, so it must derive the same trusted thread for start and resume. `DurableGraphSession` always checks its graph and thread. These checks do not replace application authentication or tenant authorization.

Subgraph responses must carry the child IDs from the pending parent interrupt. Missing, foreign, stale, or incompatible accepted-resume identities and invalid child state are rejected before parent acceptance. Delayed children remain waiting; failed, cancelled, or still-running children are not treated as completed output. Embedded child definitions are registered recursively when the parent is defined.

The SDK does not add cancellation cascading or asynchronous child orchestration. Keep the plugin's structured-concurrency and cancellation wrappers. Review runtime subclasses because the old protected `persistQueuedInterrupt()` hook no longer participates in persistence.

A non-recoverable Laravel AI streaming `Error` now raises `Heiner\AgentGraph\Exceptions\AgentStreamException` and follows the node failure/retry policy. Text already sent to a client cannot be withdrawn; consumers must wait for a successful canonical outcome before treating it as complete.

### Coordinated rollout checklist

1. Prepare the SDK and consuming application together. Adapt custom stores and runtime subclasses, preserve graph versions for active runs, and verify the affected task, interrupt/resume, subgraph, stream-error, and queue flows in staging.
2. Back up the AgentGraph database and record active runs, tasks, leases, and queued jobs. Reconcile unknown external outcomes before deciding which work may be retried.
3. Pause new work and drain in-flight operations. Stop every old PHP entry point that can execute AgentGraph: web processes, Octane, queue workers, Horizon, and scheduler processes. **Never run 0.15 and 0.16 processes concurrently**, even though the schema change is additive; old code can bypass the new ownership checks.
4. Deploy the reviewed 0.16 build and adapted application while execution remains paused. This is an unreleased upgrade procedure, not a claim that a 0.16 tag is available.
5. Publish only missing package migrations, without `--force`, then migrate and run doctor before starting application processes:

```bash
php artisan vendor:publish --tag=agent-graph-migrations
php artisan migrate
php artisan agent-graph:doctor
```

The new package migration is `2026_08_31_010000_add_claim_token_to_agent_graph_node_executions.php`; Laravel may change the published timestamp. It adds nullable `claim_token` to the configured node-execution table, preserving existing rows. Do not overwrite published migrations or application config. Confirm the configured database connection and treat doctor `FAIL` output, including a missing claim-token column, as a rollout blocker.

6. Start all web, Octane, Horizon, queue, and scheduler processes on the same new build. Verify the host integration before reopening normal traffic.
7. Recover only runs with valid durable authority. Waiting delays remain a `recover()` no-op: if delay dispatch was lost, inspect the pending delay and re-enqueue it through the application's scheduler using its existing identity and due time.
8. If rollback is required, pause and drain all execution again and reconcile work completed since cutover. Restoring database state cannot undo external effects.

`once()` does not guarantee exactly-once external execution. A remote operation may succeed before its receipt is stored, or outlive its lease. Use stable operation keys and provider idempotency where available. Replay and fork create new run IDs; a task key containing the run ID changes too and can intentionally execute new work. See [idempotent tasks](docs/concepts/idempotent-tasks.md) and the [production guide](docs/guides/production.md).

## 0.15.0 To 0.15.1

Resume and state-edit acceptance now persist a bounded recovery marker in the run metadata in the same database transaction as pending interrupt resolution and the `running` transition. No migration is required. Use `AgentGraph::recover($runId)` to continue a `running` run after process loss; retrying the exact same accepted resume payload also recovers it. Different payloads remain rejected.

Cancel now resolves a pending interrupt with a typed `cancelled` response atomically with the terminal run update. Applications that previously performed best-effort interrupt cleanup after `cancel()` should remove that duplicate cleanup when adopting this SDK version.

Queued-superstep frontier rows are now committed atomically before dispatch. Recovery may redispatch pending/running execution jobs or the continuation aggregator; completed execution claims remain idempotent.

No database migration is required. Update with `composer require heiner/agent-graph:^0.15.1 --with-all-dependencies`, then run interrupt/resume, cancellation, queue-worker, and recovery checks in the host application.

## 0.14 To 0.15

AgentGraph 0.15 hardens the graph contract APIs introduced in 0.14. Runtime execution remains compatible, but graph metadata and release validation are more explicit.

### Graph schema export and manifests

`GraphSchemaExporter` is now the neutral source for exact AgentGraph state schema export. It normalizes aliases such as `int` to `integer`, `float` to `number`, and `bool` to `boolean`, while preserving nullable flags, unions, enum values, array items, object properties, and message-channel format metadata.

`GraphManifest::toArray()` now returns manifest v2 by default and includes `manifest_version: 2`. Node entries are SDK-neutral: `id`, `metadata`, `input_channels`, `output_channels`, `can_interrupt`, and `side_effects`. If a tool still needs the old PHP-oriented node shape with `class` and `callable`, call `GraphManifest::toArray(1)` explicitly.

Use the new node metadata APIs to populate v2 manifests:

```php
StateGraph::make('support_triage')
    ->node('answer', AnswerNode::class)
    ->nodeMeta('answer', ['label' => 'Answer', 'type' => 'agent'])
    ->nodeChannels('answer', input: ['input'], output: ['answer'])
    ->nodeCanInterrupt('answer')
    ->nodeSideEffects('answer', ['read', 'write']);
```

### Validation release gates

`agent-graph:validate` now reports additional warnings:

- `terminal_path` for reachable nodes without an explicit outgoing route.
- `conditional_without_default` for conditionals without a `default` route.
- `mixed_static_conditional_outgoing` when a node defines both static and conditional outgoing routes; runtime conditionals take precedence.

Warnings remain warnings by default. Add `--strict` in CI when warnings should fail the release gate. Add `--json` to emit a machine-readable report without text output:

```bash
php artisan agent-graph:validate --strict --json
```

### Typed interrupt response validation

Typed interrupt contracts now include `response_schema` metadata for slot-value, approval, and choice waitpoints. Existing `resume()` and `resumeStrict()` behavior is unchanged. Use `AgentGraph::resumeContract($runId, [...])` when a public endpoint should validate the pending typed interrupt response before the interrupt is resolved:

```php
AgentGraph::resumeContract($runId, [
    'interrupt_id' => $interruptId,
    'answer_type' => 'approve',
]);
```

Free-form interrupt payloads continue to work and are ignored by contract-aware response validation.

## 0.13 To 0.14

AgentGraph 0.14 adds runtime contract and release-gate APIs without removing the 0.13 runtime surface.

### Typed interrupt contracts

Existing `NodeResult::interrupt($type, $payload, $writes)` calls continue to work. New code can use `InterruptContract` plus `NodeResult::interruptContract()` when a consuming app needs a stable machine-readable waitpoint payload:

```php
return NodeResult::interruptContract(
    InterruptContract::slotValue(
        nodeId: 'collect_email',
        question: 'Which email should receive the follow-up?',
        slot: 'email',
        inputType: 'email',
    ),
);
```

Consumer projections should treat `contract_version`, `node_id`, `output`, and `interaction.kind` as the stable contract shape. Apps that already store custom interrupt payloads do not need to migrate historical rows.

### Graph manifests and validation

Compiled graph definitions now expose `manifest()` for read-only metadata. `AgentGraphManager::manifest($key)` and `AgentGraphManager::validate($key)` are additive read APIs for tools, visual editors, CI checks, and admin diagnostics.

`agent-graph:validate {graph?}` validates graph definitions registered in the current Laravel process. Host apps that register graphs during service-provider boot can add this command to release smoke tests.

The command now fails when no graph definitions are registered. This is intentional for release gates: an empty registry usually means the host app did not boot the production graph definitions. Use `--allow-empty` only when an empty registry is explicitly expected.

### GraphTool input schemas

`GraphTool::schema()` now derives optional `input` properties from the registered graph state schema. If code expected the tool schema to contain only a generic nullable object, update tests to allow concrete `properties`. Runtime tool handling remains compatible.

Use `GraphTool::schemaInput()` to expose a narrower public tool input shape. This is recommended when the graph state contains internal channels that should not be shown as parent-agent input. Multi-type state unions are represented exactly in `GraphManifest`; the Laravel AI tool schema uses conservative fallbacks for unions because the current Laravel JSON schema factory cannot express arbitrary `anyOf`/union contracts through its `Type` objects.

### Security audit check

`composer check` now includes `composer audit --no-dev`. Release environments should keep dependency resolution current enough for this command to pass.

## 0.13 To v1

AgentGraph 0.13 and 0.14 are the hardened pre-v1 release lines. v1 freezes the durable runtime core, documents the public API, and tightens validation around state, resume, queues, and time travel.

## Public API stability

The stable v1 API surface is documented in `docs/api-reference.md`. The core stable APIs are `StateGraph`, `Node`, `NodeContext`, `NodeResult`, `AgentGraph` runtime methods, `RunResult`, `RunSnapshot`, `AgentNode`, `GraphTool`, and store contracts.

Experimental time-travel APIs are public and tested, but not part of the stable v1 core: `checkpoint()`, `replay()`, `fork()`, and `timeTravelChildren()`.

## Store contract adapter updates

Custom store adapters must implement the v1 contract additions:

- `RunStore::list(array $filters = [], int $limit = 50): array`
- `RunStore::latestForThreadGraph(string $threadId, string $graphKey, array $statuses = []): ?array`
- `RunStore::listChildRuns(string $parentRunId, int $limit = 50): array`
- `RunStore::listTimeTravelChildren(string $checkpointId, int $limit = 50): array`
- `CheckpointStore::find(string $checkpointId): ?array`
- `InterruptStore::find(string $interruptId): ?array`
- `InterruptStore::listForRun(string $runId): array`
- `InterruptStore::expirePending(mixed $now = null): int`
- `WriteStore::listForCheckpoint(string $checkpointId): array`
- `TaskStore::list(array $filters = [], int $limit = 50): array`
- `MemoryStore::exportScope(MemoryScope $scope, ?string $namespace = null): array`
- `MemoryStore::deleteScope(MemoryScope $scope): int`
- `MemoryStore::deleteNamespace(MemoryScope $scope, string $namespace): int`
- `MemoryStore::deleteKey(MemoryScope $scope, string $namespace, string $key): int`

New optional contracts are available for package/default adapters and custom extensions:

- `LeasingTaskStore`
- `NodeExecutionStore`
- `MemoryExtractor`
- `EmbeddingGenerator`
- `VectorMemoryStore`

Applications that expose memory inspection should resolve `EnumerableMemoryStore::class` for namespace listing. Custom memory stores can implement it with `listNamespace(array $scopes, string $namespace): array`.

Run the new additive package migrations when using the package stores. They add interrupt expiry, queued node execution records, and runtime invariants for checkpoints and queued node executions. Existing published migrations remain valid.

Before deploying the runtime invariant migration against an application with historical AgentGraph data:

- Clean duplicate checkpoint rows for the same `run_id` and `step` before adding the unique index.
- Clean duplicate queued node execution rows for the same `run_id`, `step`, and `schedule_index` before adding the unique index.
- Resolve or expire duplicate pending interrupts for a run before deploying the invariant change.

`NodeExecutionStore` now owns the queued node lifecycle for `queued_supersteps`: schedule, find, claim, complete, interrupt, fail, and list by run/step. Custom adapters must persist execution IDs, node state, base state, resume payloads, leases, and final result payloads.

`TaskStore::list()` is read-only and supports `run_id`, `node_id`, `checkpoint_id`, and `status` filters for inspector UIs.

`RunStore::listChildRuns()` is read-only and filters decoded run metadata by `meta.parent.run_id`. The package database store does not require a migration for this metadata-only lineage.

## Delay scheduling

Delay interrupts now schedule through `DelayScheduler::class`. The package default still dispatches `ContinueDelayedGraphJob`, so existing queue behavior is unchanged. Applications that need custom delayed execution can bind their own `DelayScheduler` implementation.

## State schema validation

State schema validation is stricter in v1:

- `run()` validates input keys and value types before creating a run.
- `resume()` validates known state keys while still allowing extra payload fields for compatibility.
- `resumeWithStateEdit()` and `fork()` reject unknown keys and invalid value types before mutating runtime state.
- Invalid node writes fail the run instead of persisting invalid state.

Review graphs that relied on loosely typed values such as string numbers for `int` channels.

## Resume and state-edit resume

Use `AgentGraph::resume($runId, ['interrupt_id' => $interruptId, ...])` for normal input and approval flows.

Use `AgentGraph::resumeStrict($runId, [...])` for public endpoints that should reject unknown resume payload keys. Normal `resume()` remains permissive for backward compatibility.

During the resumed node invocation, `$context->hasResumePayload()`, `$context->resumePayload()`, and `$context->interruptId()` expose the original resume payload separately from merged graph state.

Use `AgentGraph::resumeWithStateEdit($runId, $interruptId, $statePatch, $resolvedBy)` for manual state correction. It only accepts pending `state_edit` interrupts and validates the patch before resolving the interrupt.

## Structured errors

Failed runs now return structured error arrays with `message`, `exception_class`, `code`, `previous`, and optional `details` or `meta`. Existing code that only reads `error()['message']` remains compatible. Code that relied on graph-tool exception errors using a `type` key should switch to `exception_class`.

## GraphTool mapping hooks

`GraphTool` now supports `input()`, `output()`, and `meta()` hooks. These hooks are additive and do not replace Laravel AI tool invocation. Use them to map tool requests and responses; keep lifecycle persistence in run-event observers.

`GraphTool` keeps its existing JSON response shape. Use `AgentGraph::durableTool()` or `AgentGraph::session()` when an application needs active-run-per-thread semantics, status, resume, or cancel behavior.

## Runtime hardening APIs

Per-node timeout and concurrency policies are additive:

- `StateGraph::timeout($nodeId, $seconds)`
- `StateGraph::concurrency($nodeId, limit: 1, key: null)`

Timeouts are wall-clock checks after node execution returns. Concurrency uses the configured AgentGraph lock provider and does not alter Laravel AI providers, queues, or streaming.

Unknown reducer strings now throw instead of silently falling back to last-write-wins. Fix typos such as `apend` to `append`.

`StateGraph::concurrency()` currently supports exclusive locks only. Calls with `limit > 1` now throw because semaphore concurrency is not implemented.

Multiple edges from `StateGraph::START` now execute as the first superstep. All entry nodes read the same initial state, and concurrent writes to the same channel still require an explicit reducer.

Cache locks fail closed by default through `agent-graph.locks.fail_without_provider=true`. Outside local throwaway tests, keep that setting enabled so missing atomic lock support fails clearly.

Resume, state-edit resume, cancel, queued continuation, and delayed continuation paths are protected by run locks. This closes race windows around interrupt resolution, terminal run guards, and checkpoint continuation.

Task leases use `agent-graph.tasks.lease_seconds`. Choose a lease duration longer than the expected external side-effect call.

Interrupt expiry is opt-in through `NodeResult::withInterruptPolicy(InterruptPolicy::expiresAfter(...))`. Call `AgentGraph::expireInterrupts()` from scheduled maintenance if your app uses expiring review flows.

`queued_supersteps` is opt-in through `agent-graph.execution.mode`. In that mode, `run()` and `resume()` usually return `running` after scheduling queue jobs. Workers must boot the same graph definitions and process `NodeExecutionJob` and `ContinueSuperstepJob` on the configured queue.

Queue jobs now use package-level defaults for tries, timeout, and backoff, and include AgentGraph tags for queue dashboards and worker telemetry.

## Laravel AI compatibility

AgentGraph only uses Laravel AI public contracts, response DTOs, and streaming events. Do not build custom adapters that depend on `Laravel\Ai\Gateway`, `Laravel\Ai\Providers`, provider concerns, or Laravel AI's Vercel protocol internals from AgentGraph code.

`AgentNode` can now write structured output, tool calls, tool results, steps, and stream event arrays into graph state using public Laravel AI response objects.

## Subgraphs and memory

`SubgraphNode` is now available for child graph execution. Child runs are persisted as normal runs and use `run.meta.parent` lineage. If child graphs can interrupt, callers must resume the parent with the bubbled `child_run_id` and `child_interrupt_id`.

`AgentGraph::memory()` adds extraction/export/delete helpers. Default memory/vector bindings are deterministic and infrastructure-free. Laravel AI can provide embeddings, but durable vector storage is application-controlled through `VectorMemoryStore`.

`PgvectorMemoryStore` is an optional experimental adapter for semantic memory, similar-case lookup, example selection, and semantic routing. It is not used for runs, checkpoints, interrupts, queues, or audit logs. It now rejects empty or non-finite embeddings and returns an empty result for empty-scope or non-positive-limit searches.

## Time travel replay and fork safety

Replay and fork create new runs from existing checkpoint state. They can execute downstream LLM, API, CRM, email, payment, or webhook nodes again.

Before using time travel in production, wrap irreversible side effects in `$context->tasks()->once()` with stable task keys and input hashes. Use `AgentGraph::timeTravelChildren($checkpointId)` to audit replay and fork branches created from a source checkpoint.

Replay and fork now also store `run.meta.parent` with `relationship` set to `replay` or `fork`, so generic inspectors can list them with `AgentGraph::childRuns($sourceRunId)`. This is additive metadata and does not enable full subgraph orchestration.

## Graph version compatibility

Resume, replay, and fork require the persisted `graph_version` to match the currently registered graph definition. When routing, node behavior, or state semantics change incompatibly, register a new graph version and do not resume old runs against the new definition.

Before upgrading to v1:

1. Read `CHANGELOG.md` for breaking changes.
2. Run `php artisan agent-graph:doctor`.
3. Run your graph and interrupt/resume flows against a staging database.
4. Verify idempotent task keys for external side effects.
5. Re-run any chatbot integration tests that consume `GraphTool` JSON.
6. Update custom store adapters for the v1 contract additions.
7. Review state schemas for value types that were previously accepted loosely.
8. Run the additive hardening migrations for interrupt expiry, queued node execution records, and runtime invariants.
