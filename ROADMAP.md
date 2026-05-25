# AgentGraph Roadmap

AgentGraph `0.9.x` is the public beta for real Laravel app testing. The goal for v1 is a hardened MVP for durable agent workflows in Laravel, not a complete LangGraph platform clone.

## 0.9 Beta Scope

Implemented and ready for sandbox testing:

- Fluent PHP graph definitions with nodes, static edges, conditional routing, `__start__`, and `__end__`.
- Typed state channels and reducers.
- Durable runs, checkpoints, writes, resume, cancellation, max-step protection, and persisted failures.
- Human-in-the-loop interrupts for input, approval, delay, webhook, manual review, and state edit.
- Delay interrupts scheduled through `ContinueDelayedGraphJob`.
- Idempotent tasks with task-key/input-hash protection.
- Scoped memory for run, thread, actor, tenant, application, and global scopes.
- Memory TTL filtering, fallback order, keyword search, metadata, confidence, source, usage count, and `last_used_at`.
- Database and in-memory stores.
- Laravel events and redacted traces with payload limits.
- Laravel AI `AgentNode`, including streamed `TextDelta` handling.
- `GraphTool` for durable graph execution from Laravel AI tools.
- Queue jobs, cache locks, install/make/doctor/prune commands.
- Fresh Laravel sandbox validation through a local path repository.

## v1 Hardening Checklist

These items should be completed before tagging `v1.0.0`:

- Freeze and document the public API for `StateGraph`, `Node`, `NodeContext`, `NodeResult`, `AgentGraph`, `AgentNode`, `GraphTool`, and store contracts.
- Add API docs for every v1-stable method, including return shapes and failure behavior.
- Add more crash/retry coverage around queue workers, latest-checkpoint resume, delayed continuation, and interrupted runs.
- Add explicit state-inspection APIs for active and completed runs.
- Add a safe state-edit resume flow that validates edited state against the graph schema.
- Add tenant/actor memory examples that make cross-tenant boundaries unambiguous.
- Add a compatibility CI matrix for PHP 8.3/8.4, Laravel 12/13, and `laravel/ai ^0.7 || ^1.0`.
  `laravel/ai ^1.0` is declared in `composer.json` for forward compatibility, but should only be enabled in blocking CI after a 1.x release exists upstream.
- Test the Filament Agentic Chatbot refactor against the 0.9 package before locking v1.
- Document any breaking changes from 0.9 in `CHANGELOG.md` and `UPGRADE.md`.

## Post-v1 Features

These are useful but intentionally outside the v1 MVP:

- True parallel execution with fan-out/fan-in scheduling.
- Reducer conflict handling for concurrent branch writes.
- Stateful subgraphs with inherited or isolated checkpoint stores.
- Graph-as-subgraph composition separate from graph-as-tool.
- Time travel, replay, checkpoint history APIs, and checkpoint forking.
- Visual state inspection and state editing UI.
- JSON graph import/export and schema serialization.
- Semantic/vector memory adapters, including optional pgvector support.
- Memory compaction and summarization policies.
- OpenTelemetry export.
- LangSmith-like observability dashboards or external trace adapters.
- Visual workflow editor.
- Per-node retry, timeout, backoff, cache, and concurrency policies.
- Native deployment/API server comparable to LangGraph Platform.
- Multi-process scheduler for long-running background graph runs.

## Not Planned For Core

These should stay in consuming apps or optional adapters:

- Filament-specific UI classes.
- Provider-specific Laravel AI internals.
- Product-specific chatbot prompts or workflows.
- Mandatory Redis, pgvector, OpenTelemetry, or external observability dependencies.
