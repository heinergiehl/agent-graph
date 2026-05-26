# AgentGraph Roadmap

AgentGraph `0.12.x` is the active public beta for real Laravel app testing. The goal for v1 is a hardened MVP for durable agent workflows in Laravel, not a complete LangGraph platform clone.

## 0.9 Beta Scope

Implemented and ready for sandbox testing:

- Fluent PHP graph definitions with nodes, static edges, conditional routing, `__start__`, and `__end__`.
- Typed state channels and reducers.
- Durable runs, checkpoints, writes, resume, cancellation, max-step protection, and persisted failures.
- Human-in-the-loop interrupts for input, approval, delay, webhook, manual review, and state edit.
- Delay interrupts scheduled through a replaceable `DelayScheduler`; the default still dispatches `ContinueDelayedGraphJob`.
- Idempotent tasks with task-key/input-hash protection.
- Scoped memory for run, thread, actor, tenant, application, and global scopes.
- Memory TTL filtering, fallback order, namespace listing, keyword search, metadata, confidence, source, usage count, and `last_used_at`.
- Database and in-memory stores.
- Laravel events and redacted traces with payload limits.
- Optional run-event observation for lifecycle, checkpoint, interrupt, failure, and stream-delta workflow events.
- Laravel AI `AgentNode`, including streamed `TextDelta` handling and optional direct text delta callbacks.
- `GraphTool` for durable graph execution from Laravel AI tools.
- Queue jobs, cache locks, install/make/doctor/prune commands.
- Runtime inspection with run snapshots, run listing, checkpoint writes, pending interrupts, optional traces, and read-only run timelines.
- Read-only task inspection for durable side-effect debugging.
- Experimental checkpoint inspection, replay, fork, and lineage APIs for time-travel workflows.
- Per-node retry policies for transient thrown node exceptions.
- State schema key/type validation for run input, resume, state edit, fork, and node writes.
- Safe state-edit resume with graph-schema validation.
- Delayed continuation retry guards for final runs and stale delay interrupts.
- Fresh Laravel sandbox validation through a local path repository.

## 0.10 LangGraph-Parity Scope

Implemented for the next beta:

- Deterministic superstep execution for static and conditional fan-out.
- Dynamic `Send` API for map/reduce style fan-out.
- Reducer-enforced concurrent writes for parallel branches.
- One checkpoint per superstep with all completed nodes and scheduled `Send` metadata.
- Timeline support for parallel checkpoints through `nodeIds()` / `completed_nodes`.

## 0.11 Run Event Observation Scope

Implemented for the next beta:

- `RunEvent` DTO for normalized workflow events.
- Per-run `onEvent()` callbacks and optional `RunResult::events()` collection.
- Normalized lifecycle events for runs, nodes, checkpoints, interrupts, failures, and resumes.
- `stream.delta` observation for existing Laravel AI `GraphStreamDelta` payloads.
- No SSE helper, no Vercel protocol adapter, and no replacement for Laravel AI model streaming.

## 0.12 Node Retry Policy Scope

Implemented for the next beta:

- `StateGraph::retry()` for per-node retry policies.
- `RetryPolicy` with max attempts, initial delay, backoff, max delay, and optional retry predicate.
- Synchronous runtime retry for thrown node exceptions only.
- `node.retrying` Laravel events, traces, and normalized run events.
- Retry metadata under `runtime.retry` on successful write metadata.
- No timeout, cache, concurrency limit, queue-backed parallelism, or Laravel AI provider hooks in this scope.

## v1 Hardening Checklist

These items should be completed before tagging `v1.0.0`:

- Review the documented public API in `docs/api-reference.md` before tagging.
- Add tenant/actor memory examples that make cross-tenant boundaries unambiguous.
- Keep the compatibility CI matrix green for PHP 8.3/8.4, Laravel 12/13, and `laravel/ai ^0.7`.
  `laravel/ai ^1.0` is declared in `composer.json` for forward compatibility, but should only be enabled in blocking CI after a 1.x release exists upstream.
- Test the Filament Agentic Chatbot refactor against the 0.12 package before locking v1.
- Review `CHANGELOG.md` and `UPGRADE.md` before tagging.

Implemented v1 hardening:

- Public API reference for `StateGraph`, `Node`, `NodeContext`, `NodeResult`, `AgentGraph`, `AgentNode`, `GraphTool`, runtime DTOs, and store contracts.
- Runtime inspection APIs for active, completed, interrupted, delayed, failed, and cancelled runs.
- Generic run timeline inspector with state diffs, redaction, node metadata, and skipped-step status.
- Safe state-edit resume with schema key/type validation.
- Queue retry guards for final runs, stale delay interrupts, latest-checkpoint resume, and duplicate delayed jobs.
- Experimental checkpoint inspection, replay, fork, and lineage APIs.
- Graph version compatibility checks for resume, replay, and fork.
- Deterministic supersteps and dynamic `Send` fan-out without Laravel AI provider coupling.
- Additive run-event observation without replacing Laravel AI streaming or changing `GraphTool` JSON.
- Per-node retry policies for thrown node exceptions without database migrations.
- Resume context accessors for human-in-the-loop nodes.
- Structured runtime errors for run results, graph tools, traces, and timelines.
- Replaceable delay scheduling through `DelayScheduler`.
- Enumerable memory listing through `EnumerableMemoryStore`.
- Task inspection through `AgentGraph::tasks()`.
- Bounded GraphTool input, output, and run metadata mapping hooks.
- Standard `meta.node` conventions for inspector and timeline UIs.
- Parent/child run lineage metadata through `run.meta.parent` and `AgentGraph::childRuns()`.
- Compatibility CI matrix for PHP 8.3/8.4, Laravel 12/13, and `laravel/ai ^0.7`.

## Post-v1 Features

These are useful but intentionally outside the v1 MVP:

- Queue-backed true parallel execution across workers.
- Stateful subgraphs with inherited or isolated checkpoint stores.
- Graph-as-subgraph composition separate from graph-as-tool.
- Visual timeline tooling and advanced time-travel controls beyond the generic timeline and experimental replay/fork/lineage APIs.
- Visual state inspection and state editing UI.
- JSON graph import/export and schema serialization.
- Semantic/vector memory adapters, including optional pgvector support.
- Memory compaction and summarization policies.
- OpenTelemetry export.
- Optional HTTP/SSE or broadcast adapters built outside the SDK core on top of `RunEvent`.
- LangSmith-like observability dashboards or external trace adapters.
- Visual workflow editor.
- Per-node timeout, cache, and concurrency policies.
- Native deployment/API server comparable to LangGraph Platform.
- Multi-process scheduler for long-running background graph runs.

## Not Planned For Core

These should stay in consuming apps or optional adapters:

- Filament-specific UI classes.
- Provider-specific Laravel AI internals.
- Product-specific chatbot prompts or workflows.
- Mandatory Redis, pgvector, OpenTelemetry, or external observability dependencies.
