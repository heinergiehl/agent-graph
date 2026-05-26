# Changelog

All notable changes to AgentGraph are documented here.

## v1.0.0 - Unreleased

Target: hardened MVP API stability after 0.12 sandbox and chatbot integration testing.

### Added

- Added stable runtime inspection APIs: `AgentGraph::inspect()` and `AgentGraph::runs()`.
- Added `RunSnapshot` for read-only run inspection with optional checkpoint history and traces.
- Added explicit `AgentGraph::resumeWithStateEdit()` for schema-validated human state correction flows.
- Added experimental time-travel APIs: `checkpoint()`, `replay()`, `fork()`, and `timeTravelChildren()`.
- Added `CheckpointSnapshot` for read-only checkpoint inspection.
- Added `RunEvent` observation with `PendingGraphRun::onEvent()`, `PendingGraphRun::collectEvents()`, `RunResult::events()`, and optional event callbacks for resume, replay, and fork APIs.
- Added per-node retry policies for transient thrown node exceptions.
- Added resume context accessors on `NodeContext`: `hasResumePayload()`, `resumePayload()`, and `interruptId()`.
- Added `DelayScheduler` with a default queue-backed implementation for replaceable delay interrupt scheduling.
- Added `EnumerableMemoryStore::listNamespace()` for memory inspection UIs.
- Added `AgentGraph::tasks()` and `TaskStore::list()` for read-only idempotent task inspection.
- Added `AgentNode::onTextDelta()` for direct streamed text delta callbacks.
- Added `GraphTool::input()`, `GraphTool::output()`, and `GraphTool::meta()` mapping hooks.
- Added stable `meta.node` key conventions for timeline and inspector UIs.
- Added parent/child run lineage metadata with `PendingGraphRun::parent()`, `AgentGraph::childRuns()`, `RunResult::meta()`, and `RunSnapshot::parent()`.
- Added state schema type validation for run input, resume payloads, state-edit patches, fork patches, and node writes.
- Added graph version compatibility checks for resume, replay, and fork.
- Added API reference documentation for the v1 public surface.

### Changed

- Store contracts now include checkpoint lookup, checkpoint write listing, interrupt listing, run listing, child-run listing, and time-travel child listing methods used by inspection and time travel.
- `TaskStore` adapters must expose read-only task listing for inspector UIs.
- Failed run payloads now include structured error metadata: `message`, `exception_class`, `code`, `previous`, and optional `details`/`meta`.
- `resume()` remains compatible with extra payload fields, but known state schema keys are now type-validated.
- Replay and fork create new runs and require persisted `graph_version` to match the currently registered graph definition.

### Hardened

- Delayed queue continuation no-ops for final runs and stale delay interrupts.
- Queue retry coverage verifies duplicate delayed jobs do not duplicate checkpoints or writes.
- State-edit resume fails before resolving interrupts when the interrupt ID is stale, wrong, or not a `state_edit` interrupt.
- Invalid node writes fail the run through the normal failed-run path instead of persisting invalid state.
- Time-travel fork patches validate schema keys and types before creating a new run.

### Documentation

- Added production guidance for runtime recovery, queue retry safety, state edits, replay/fork side-effect safety, and API stability.

## 0.12.1 - 2026-05-26

### Added

- Added generic parent/child run lineage metadata with `PendingGraphRun::parent()`, `AgentGraph::childRuns()`, `RunResult::meta()`, and `RunSnapshot::parent()`.
- Replay and fork runs now also store `run.meta.parent` with `relationship` set to `replay` or `fork`, while preserving checkpoint-specific `time_travel` metadata.

### Changed

- `RunStore` adapters now expose `listChildRuns()` for read-only inspector and lineage UIs without requiring a database migration.

## 0.12.0 - 2026-05-26

### Added

- Added 0.12 beta release hardening docs, compatibility notes, and Composer branch alias alignment.
- Added per-node retry policies through `StateGraph::retry()` and `RetryPolicy`.
- Added `NodePolicy` metadata on compiled graph definitions through `nodePolicy()` and `nodePolicies()`.
- Added `GraphNodeRetrying`, normalized `node.retrying` run events, and `node.retrying` trace records.
- Added retry metadata under persisted write/checkpoint result metadata at `runtime.retry`.

### Notes

- Retry policies apply only to thrown node exceptions. They do not retry `NodeResult::fail()`, interrupts, delays, or schema-validation failures.
- Retried nodes can repeat side effects. Use `$context->tasks()->once()` for API calls, emails, payments, CRM writes, and other irreversible work.

## 0.11.0 - 2026-05-26

### Added

- Added deterministic superstep execution for static and conditional fan-out.
- Added dynamic `Send` API for map/reduce style fan-out.
- Added reducer-enforced concurrent writes for superstep branches.
- Added normalized run-event observation with `RunEvent`, `onEvent()`, `collectEvents()`, and collected `RunResult::events()`.
- Added `stream.delta` run events for existing Laravel AI `GraphStreamDelta` payloads without changing Laravel AI streaming behavior.

### Documentation

- Documented run-event observation as workflow events, not SSE, Vercel protocol support, or a Laravel AI streaming replacement.

## 0.10.0 - 2026-05-26

### Added

- Added generic run timeline inspection for debuggers, admin UIs, and replay tooling.
- Added checkpoint `stateBefore()` and `stateAfter()` helpers.
- Added state diffs with redaction/truncation for timeline steps.

## 0.9.0-beta - 2026-05-25

Public beta for real Laravel sandbox testing before v1.

- Added durable graph runtime, checkpoints, writes, interrupts, resume, idempotent tasks, scoped memory, tracing, queue jobs, commands, Laravel AI `AgentNode`, and graph-as-tool support.
- Added stream delta dispatching through `GraphStreamDelta` and redacted stream traces.
- Added stable `GraphTool` JSON responses with `status`, `run_id`, `thread_id`, `state`, `interrupt`, and `error`.
- Added delayed interrupt scheduling via `ContinueDelayedGraphJob`.
- Hardened memory TTL filtering, usage accounting, serialization failures, task key reuse, and persistence rollback behavior.
- Added package doctor/prune commands and release documentation.
