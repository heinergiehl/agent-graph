# Upgrade Guide

## 0.13 Beta To v1

AgentGraph 0.13 is the active public beta. v1 freezes the durable runtime core, documents the public API, and tightens validation around state, resume, queues, and time travel.

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

Run the new additive package migrations when using the package stores. They add interrupt expiry and queued node execution records. Existing published migrations remain valid.

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

Task leases use `agent-graph.tasks.lease_seconds`. Choose a lease duration longer than the expected external side-effect call.

Interrupt expiry is opt-in through `NodeResult::withInterruptPolicy(InterruptPolicy::expiresAfter(...))`. Call `AgentGraph::expireInterrupts()` from scheduled maintenance if your app uses expiring review flows.

`queued_supersteps` is opt-in through `agent-graph.execution.mode`. In that mode, `run()` and `resume()` usually return `running` after scheduling queue jobs. Workers must boot the same graph definitions and process `NodeExecutionJob` and `ContinueSuperstepJob` on the configured queue.

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
8. Run the additive hardening migrations for interrupt expiry and queued node execution records.
