# Upgrade Guide

## 0.12 Beta To v1

AgentGraph 0.12 is the active public beta. v1 freezes the durable runtime core, documents the public API, and tightens validation around state, resume, queues, and time travel.

## Public API stability

The stable v1 API surface is documented in `docs/api-reference.md`. The core stable APIs are `StateGraph`, `Node`, `NodeContext`, `NodeResult`, `AgentGraph` runtime methods, `RunResult`, `RunSnapshot`, `AgentNode`, `GraphTool`, and store contracts.

Experimental time-travel APIs are public and tested, but not part of the stable v1 core: `checkpoint()`, `replay()`, `fork()`, and `timeTravelChildren()`.

## Store contract adapter updates

Custom store adapters must implement the v1 contract additions:

- `RunStore::list(array $filters = [], int $limit = 50): array`
- `RunStore::listChildRuns(string $parentRunId, int $limit = 50): array`
- `RunStore::listTimeTravelChildren(string $checkpointId, int $limit = 50): array`
- `CheckpointStore::find(string $checkpointId): ?array`
- `InterruptStore::find(string $interruptId): ?array`
- `InterruptStore::listForRun(string $runId): array`
- `WriteStore::listForCheckpoint(string $checkpointId): array`
- `TaskStore::list(array $filters = [], int $limit = 50): array`

Applications that expose memory inspection should resolve `EnumerableMemoryStore::class` for namespace listing. Custom memory stores can implement it with `listNamespace(array $scopes, string $namespace): array`.

No new database migration is required for these APIs when using the package stores.

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

During the resumed node invocation, `$context->hasResumePayload()`, `$context->resumePayload()`, and `$context->interruptId()` expose the original resume payload separately from merged graph state.

Use `AgentGraph::resumeWithStateEdit($runId, $interruptId, $statePatch, $resolvedBy)` for manual state correction. It only accepts pending `state_edit` interrupts and validates the patch before resolving the interrupt.

## Structured errors

Failed runs now return structured error arrays with `message`, `exception_class`, `code`, `previous`, and optional `details` or `meta`. Existing code that only reads `error()['message']` remains compatible. Code that relied on graph-tool exception errors using a `type` key should switch to `exception_class`.

## GraphTool mapping hooks

`GraphTool` now supports `input()`, `output()`, and `meta()` hooks. These hooks are additive and do not replace Laravel AI tool invocation. Use them to map tool requests and responses; keep lifecycle persistence in run-event observers.

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
