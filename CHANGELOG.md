# Changelog

All notable changes to AgentGraph are documented here.

## 0.13.0-beta.2 - 2026-05-27

Target: hardened 0.13 beta API stability after sandbox and chatbot integration testing.

### Changed

- `AgentGraph::session(...)->run()` now performs active-run lookup and run creation under an AgentGraph session lock. `AgentGraph::graph(...)->thread(...)->run()` continues to intentionally create a new run.
- `resume()`, `resumeWithStateEdit()`, and `cancel()` now reject terminal `completed`, `cancelled`, and `failed` runs instead of mutating historical run state.
- State schema validation now rejects unknown schema types and validates every item in structured array schemas.
- `PgvectorMemoryStore` now rejects empty or non-finite embeddings, and empty-scope or non-positive-limit searches now return an empty result without querying.
- The default lock TTL is now 300 seconds. Production apps should set `AGENT_GRAPH_LOCK_TTL_SECONDS` longer than the longest expected node execution.

### Fixed

- Database stores, runtime transactions, `agent-graph:doctor`, `agent-graph:prune`, and optional pgvector memory writes now consistently respect `agent-graph.database.connection` / `AGENT_GRAPH_DB_CONNECTION`.
- Delayed continuation jobs now dispatch on the configured AgentGraph execution queue connection and queue.
- Queued node execution persistence now throws on missing execution reads or updates instead of returning an empty or unrelated record.
- Package migration rollbacks now use column-based index drops so custom table names remain rollback-safe.
- The pgvector migration stub now uses the configured AgentGraph database connection for schema and direct `DB::statement()` calls, and quotes the vector table name before altering it.

### Documentation

- Documented package tables, migration/connection configuration, store drivers, queue env settings, lock TTL guidance, terminal run guards, strict schema behavior, optional experimental pgvector positioning, and prune retention behavior.

## 0.13.0-beta.1 - 2026-05-26

### Added

- Added Laravel-AI-safe runtime guardrails, including architecture tests that prevent provider, gateway, parser, and protocol internals from being imported by AgentGraph source.
- Added durable app workflow sessions and `DurableGraphTool` while keeping the existing `GraphTool` JSON contract unchanged.
- Added native subgraphs with isolated, shared, and mapped state modes plus persisted parent/child lineage.
- Added task leases, node timeout/concurrency policies, interrupt expiry policies, strict resume validation, and the structured `StateSchema` builder.
- Added enriched `AgentNode` metadata writers for structured output, public tool metadata, steps, and stream events.
- Added the memory manager surface, memory extraction/vector contracts, privacy export/delete APIs, and optional pgvector memory support.
- Added worker-backed queued supersteps with leased node execution records, `NodeExecutionJob`, and `ContinueSuperstepJob`.

### Changed

- Package migrations now use Laravel-style migration publishing and configurable AgentGraph database connections.
- Store contracts now include active-run lookup, node execution lifecycle operations, interrupt expiry, task lease inspection, and memory privacy operations.
- Composer branch alias now targets `0.13-dev`.

### Hardened

- `queued_supersteps` remains opt-in and preserves sync reducer, checkpoint, interrupt, failure, and final-run semantics.
- Laravel AI remains the owner of agents, providers, tools, structured output, and token streaming; AgentGraph only orchestrates durable graph runtime behavior around those public APIs.

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
- Added Laravel-AI-safe architecture guard coverage to prevent provider/gateway/internal imports from AgentGraph source.
- Added task leases through `LeasingTaskStore` and `locked_until` handling for idempotent side effects.
- Added `StateGraph::timeout()`, `StateGraph::concurrency()`, `TimeoutPolicy`, and `ConcurrencyPolicy`.
- Added `AgentGraph::resumeStrict()` for strict resume payload validation.
- Added `DurableGraphSession` and `DurableGraphTool` for active-run-per-thread workflows without changing `GraphTool`.
- Added native `SubgraphNode` child graph execution with isolated/shared/mapped modes and interrupt bubbling.
- Added `AgentNode` writers for structured output, tool calls, tool results, steps, and public stream events.
- Added `MemoryManager`, memory extraction/vector contracts, default deterministic memory extraction, and memory export/delete privacy APIs.
- Added interrupt expiry policies through `InterruptPolicy`, `NodeResult::withInterruptPolicy()`, and `AgentGraph::expireInterrupts()`.
- Added `StateSchema` builder for structured schema declarations.
- Added worker-backed queued supersteps through `NodeExecutionJob`, `ContinueSuperstepJob`, and leased node execution records.

### Changed

- Store contracts now include checkpoint lookup, checkpoint write listing, interrupt listing, run listing, child-run listing, and time-travel child listing methods used by inspection and time travel.
- Store contracts now include active run lookup, interrupt expiry, memory privacy operations, task lease inspection, and worker node execution lifecycle methods.
- `TaskStore` adapters must expose read-only task listing for inspector UIs.
- Package migrations now use `publishesMigrations()` and `AgentGraphMigration` so migration connection can be configured.
- Failed run payloads now include structured error metadata: `message`, `exception_class`, `code`, `previous`, and optional `details`/`meta`.
- `resume()` remains compatible with extra payload fields, but known state schema keys are now type-validated.
- Replay and fork create new runs and require persisted `graph_version` to match the currently registered graph definition.

### Hardened

- Delayed queue continuation no-ops for final runs and stale delay interrupts.
- Queue retry coverage verifies duplicate delayed jobs do not duplicate checkpoints or writes.
- Queued superstep jobs no-op for final runs, reject duplicate active node execution, and aggregate each superstep once.
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
