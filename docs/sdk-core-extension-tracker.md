# AgentGraph SDK Core Extension Tracker

This tracker records the generic SDK features that should be completed after the current v1 hardening work. Keep this file current when starting or finishing a feature so future Codex sessions can continue without rediscovering the same scope.

## Working Rules

- Prefer generic runtime, store, inspector, and adapter APIs over chatbot-specific behavior.
- Keep product UI, Filament classes, provider-specific Laravel AI internals, external dashboards, and mandatory infrastructure out of core.
- For each feature, update docs, API reference, roadmap, and tests in the same change set.
- Do not revert unrelated dirty work. At the time this tracker was created, several candidate changes were already present in the working tree.

## Priority Board

| Priority | Feature | Status | SDK fit | Current notes |
|---:|---|---|---|---|
| 1 | Resume Context API | Complete | Core | Stable `NodeContext` accessors are documented and tested. |
| 2 | Structured Runtime Errors | Complete | Core | `RuntimeError` is wired into run results, graph tools, traces, and timelines. |
| 3 | Delay Scheduler Contract | Complete | Core | `DelayScheduler` and default `QueueDelayScheduler` are documented and tested. |
| 4 | Enumerable Memory Store | Complete | Core read API | `EnumerableMemoryStore::listNamespace()` is implemented for database and in-memory stores. |
| 5 | Task Inspection API | Complete | Core read API | `AgentGraph::tasks()` and `TaskStore::list()` list run, node, checkpoint, and status filters. |
| 6 | Node Metadata Standard | Complete | Core convention | Stable `meta.node` keys are documented for inspector and timeline UIs. |
| 7 | Timeline API Stabilization | Complete | Core read API | `timeline()` is documented as the stable inspector read model. |
| 8 | GraphTool Extension Hooks | Complete | Generic adapter | `input()`, `output()`, and `meta()` are implemented without lifecycle persistence hooks. |
| 9 | AgentNode Text Delta Callback | Complete | Adapter convenience | `AgentNode::onTextDelta()` is additive to `GraphStreamDelta` and `RunEvent` streaming. |
| 10 | Child Run/Subgraph Metadata | Foundation Complete | Core inspection convention | `run.meta.parent`, `PendingGraphRun::parent()`, `AgentGraph::childRuns()`, and snapshot/result accessors are implemented; full subgraph orchestration remains post-v1. |
| 11 | Laravel AI Compatibility Guardrails | Complete | Core safety | Source architecture tests prevent provider/gateway/internal Laravel AI imports. |
| 12 | Task Leases | Complete | Core durability | Task stores use `locked_until` leases and `TaskRunner::once()` rejects active duplicates. |
| 13 | Durable Sessions/Tools | Complete | Generic adapter | `DurableGraphSession` and `DurableGraphTool` cover active-thread workflows without changing `GraphTool`. |
| 14 | Native SubgraphNode | Beta | Core runtime | Child graph execution, lineage, mapped/shared/isolated modes, and interrupt bubbling are implemented. |
| 15 | Memory Manager Contracts | Beta | Core extension | Extractor, embedding, vector store contracts plus export/delete helpers are implemented with deterministic defaults. |
| 16 | Worker-backed Queued Supersteps | Beta | Runtime foundation | Opt-in `queued_supersteps` mode dispatches node execution jobs and aggregates supersteps with persisted execution rows. |

## Stable Core Candidates

These should be considered for v1 or the next beta if tests and docs are complete:

- Resume Context API
- Structured Runtime Errors
- Delay Scheduler Contract
- Enumerable Memory Store
- Task Inspection API
- Timeline API Stabilization
- Node Metadata Standard
- Bounded GraphTool Extension Hooks
- AgentNode Text Delta Callback

## Post-v1 Candidates

These are useful but should not block the core runtime release:

- Advanced graph-as-subgraph orchestration beyond `SubgraphNode`

## Acceptance Checklist

- [ ] Public API names are documented in `docs/api-reference.md`.
- [ ] `README.md` includes the user-facing examples for any stable feature.
- [ ] `ROADMAP.md` reflects implemented, deferred, and explicitly out-of-scope items.
- [ ] Every new contract has database and in-memory coverage where applicable.
- [ ] Feature tests cover happy paths and at least one failure or edge case.
- [ ] `composer test` passes.
- [ ] `composer test:types` passes.
- [ ] `composer test:lint` passes.
- [ ] `CHANGELOG.md` and `UPGRADE.md` are updated if public behavior changes.

## Feature Notes

### Resume Context API

Goal: Nodes can distinguish normal execution from resume execution without inferring from state.

Public surface:

- `NodeContext::hasResumePayload(): bool`
- `NodeContext::resumePayload(): array`
- `NodeContext::interruptId(): ?string`

Design decision to keep explicit: resume payload may still be merged into graph state for backward compatibility, but nodes must be able to read the original resume payload separately.

### Structured Runtime Errors

Goal: Persist and return a stable error payload suitable for inspector UIs, retry decisions, and debugging.

Target shape:

```json
{
  "message": "Human-readable error",
  "exception_class": "RuntimeException",
  "code": "optional_code",
  "previous": null,
  "details": {},
  "meta": {}
}
```

`message` should always exist. Optional keys can be omitted or null, but the shape must be consistent across `RunResult`, `GraphTool`, traces, and timeline errors.

### Delay Scheduler Contract

Goal: Delay interrupts should schedule through a replaceable contract, not a hard-coded queue job.

Public surface:

- `Heiner\AgentGraph\Contracts\DelayScheduler`
- Default implementation using `ContinueDelayedGraphJob`
- Service-provider binding that apps can override

### Enumerable Memory Store

Goal: Inspector and admin UIs can list memories without using implementation-specific tables.

Public surface:

- `EnumerableMemoryStore extends MemoryStore`
- `listNamespace(array $scopes, string $namespace): array`

Before stabilizing, decide whether the first stable signature should include `limit`, `offset`, `type`, or `includeExpired`.

### Task Inspection API

Goal: Idempotent side effects should be inspectable without exposing store internals.

Candidate public surface:

- `AgentGraph::tasks(array $filters = [], int $limit = 50): array`
- filters: `run_id`, `node_id`, `checkpoint_id`, `status`
- store contract: `TaskStore::list(array $filters = [], int $limit = 50): array`

### Node Metadata Standard

Goal: Apps can build generic timeline and workflow UIs without guessing custom metadata keys.

Candidate `meta.node` keys:

- `id`
- `label`
- `type`
- `status`
- `category`
- `source`
- `description`

### Timeline API Stabilization

Goal: Treat `AgentGraph::timeline()` and `RunTimeline` DTOs as the stable inspector/replay read model.

Confirm:

- step order
- superstep `completed_nodes`
- `state_before`, `state_after`, and `state_diff` behavior
- redaction policy
- structured error shape
- node metadata location

### GraphTool Extension Hooks

Goal: Let apps adapt graph tools without subclassing or copying `GraphTool`.

Candidate hooks:

- `input(Closure $mapper)`
- `output(Closure $mapper)`
- `meta(Closure|array $meta)`
- `onResult(Closure $callback)`
- `onFailure(Closure $callback)`

Keep thread resolution through the existing `thread()` API.

### AgentNode Text Delta Callback

Goal: Apps can bridge streamed text deltas directly to WebSocket, SSE, or chat clients while AgentGraph still emits normal run events and traces.

Public surface:

- `AgentNode::onTextDelta(Closure $callback): self`

Callback arguments should be documented and stable.

### Child Run/Subgraph Metadata

Goal: When nested graphs or child runs are added, parent-child lineage can be inspected generically.

Candidate run meta:

- `parent.run_id`
- `parent.checkpoint_id`
- `parent.node_id`
- `parent.depth`
- `parent.relationship`

Implemented public surface:

- `PendingGraphRun::parent(string $runId, ?string $checkpointId = null, ?string $nodeId = null, int $depth = 1, string $relationship = 'child'): self`
- `AgentGraph::childRuns(string $parentRunId, int $limit = 50): array`
- `RunStore::listChildRuns(string $parentRunId, int $limit = 50): array`
- `RunResult::meta(): array`
- `RunSnapshot::parent(): ?array`

Do not implement full subgraph orchestration until the core v1 surface is stable.
