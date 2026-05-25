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

- Public APIs will be documented as stable before tagging v1.
- Breaking changes from 0.9 are allowed but must be documented in this changelog and `UPGRADE.md`.
