# Changelog

All notable changes to AgentGraph are documented here.

## 0.9.0-beta - 2026-05-25

Public beta for real Laravel sandbox testing before v1.

- Added durable graph runtime, checkpoints, writes, interrupts, resume, idempotent tasks, scoped memory, tracing, queue jobs, commands, Laravel AI `AgentNode`, and graph-as-tool support.
- Added stream delta dispatching through `GraphStreamDelta` and redacted stream traces.
- Added stable `GraphTool` JSON responses with `status`, `run_id`, `thread_id`, `state`, `interrupt`, and `error`.
- Added delayed interrupt scheduling via `ContinueDelayedGraphJob`.
- Hardened memory TTL filtering, usage accounting, serialization failures, task key reuse, and persistence rollback behavior.
- Added package doctor/prune commands and release documentation.

## v1.0.0 - Unreleased

Target: hardened MVP API stability after 0.9 sandbox and chatbot integration testing.

### Added

- Added stable runtime inspection APIs: `AgentGraph::inspect()` and `AgentGraph::runs()`.
- Added `RunSnapshot` for read-only run inspection with optional checkpoint history and traces.
- Added explicit `AgentGraph::resumeWithStateEdit()` for schema-validated human state correction flows.
- Added experimental time-travel APIs: `checkpoint()`, `replay()`, `fork()`, and `timeTravelChildren()`.
- Added `CheckpointSnapshot` for read-only checkpoint inspection.
- Added state schema type validation for run input, resume payloads, state-edit patches, fork patches, and node writes.
- Added graph version compatibility checks for resume, replay, and fork.
- Added API reference documentation for the v1 public surface.

### Changed

- Store contracts now include checkpoint lookup, checkpoint write listing, interrupt listing, run listing, and time-travel child listing methods used by inspection and time travel.
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
