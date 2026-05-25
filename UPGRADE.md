# Upgrade Guide

## 0.9 Beta To v1

AgentGraph 0.9 is a public beta. v1 freezes the durable runtime core, documents the public API, and tightens validation around state, resume, queues, and time travel.

## Public API stability

The stable v1 API surface is documented in `docs/api-reference.md`. The core stable APIs are `StateGraph`, `Node`, `NodeContext`, `NodeResult`, `AgentGraph` runtime methods, `RunResult`, `RunSnapshot`, `AgentNode`, `GraphTool`, and store contracts.

Experimental time-travel APIs are public and tested, but not part of the stable v1 core: `checkpoint()`, `replay()`, `fork()`, and `timeTravelChildren()`.

## Store contract adapter updates

Custom store adapters must implement the v1 contract additions:

- `RunStore::list(array $filters = [], int $limit = 50): array`
- `RunStore::listTimeTravelChildren(string $checkpointId, int $limit = 50): array`
- `CheckpointStore::find(string $checkpointId): ?array`
- `InterruptStore::find(string $interruptId): ?array`
- `InterruptStore::listForRun(string $runId): array`
- `WriteStore::listForCheckpoint(string $checkpointId): array`

No new database migration is required for these APIs when using the package stores.

## State schema validation

State schema validation is stricter in v1:

- `run()` validates input keys and value types before creating a run.
- `resume()` validates known state keys while still allowing extra payload fields for compatibility.
- `resumeWithStateEdit()` and `fork()` reject unknown keys and invalid value types before mutating runtime state.
- Invalid node writes fail the run instead of persisting invalid state.

Review graphs that relied on loosely typed values such as string numbers for `int` channels.

## Resume and state-edit resume

Use `AgentGraph::resume($runId, ['interrupt_id' => $interruptId, ...])` for normal input and approval flows.

Use `AgentGraph::resumeWithStateEdit($runId, $interruptId, $statePatch, $resolvedBy)` for manual state correction. It only accepts pending `state_edit` interrupts and validates the patch before resolving the interrupt.

## Time travel replay and fork safety

Replay and fork create new runs from existing checkpoint state. They can execute downstream LLM, API, CRM, email, payment, or webhook nodes again.

Before using time travel in production, wrap irreversible side effects in `$context->tasks()->once()` with stable task keys and input hashes. Use `AgentGraph::timeTravelChildren($checkpointId)` to audit replay and fork branches created from a source checkpoint.

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
