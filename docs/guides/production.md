# Production

Use database stores as the source of truth. Cache/Redis locks are acceleration and duplicate-execution protection, not durable storage.

Recommended production settings:

- publish and run migrations
- configure queue workers
- define tenant-aware memory scopes
- keep trace redaction keys updated
- prune traces and old runs according to your retention policy
- wrap every external side effect in `$context->tasks()->once()`
- define reducers for channels written by multiple fan-out branches
- configure per-node retries only for transient thrown exceptions
- avoid storing raw secrets in state, memory, traces, task input, or interrupt payloads
- avoid doing slow network I/O inside run-event listeners

## Runtime recovery

Use `AgentGraph::inspect($runId, withHistory: true, withTraces: true)` for admin and recovery screens. It returns the latest state, current checkpoint, checkpoint history, writes, pending interrupt, traces, error, and metadata without changing run state.

Use `AgentGraph::runs($filters, $limit)` to list recent runs by `status`, `thread_id`, `graph_key`, or `graph_version`.

Use `onEvent()` or `collectEvents()` when an application needs ordered workflow observations for a single run. Listeners run synchronously in the runtime path, so keep them lightweight and move broadcasting, persistence copies, or expensive processing into application-level jobs.

Run-event observation is not model streaming. Keep Laravel AI as the owner of token streaming and provider behavior; AgentGraph only normalizes workflow events such as run lifecycle, node lifecycle, checkpoints, interrupts, failures, and existing `GraphStreamDelta` payloads.

## Human-in-the-loop state edits

Use `AgentGraph::resumeWithStateEdit($runId, $interruptId, $statePatch, $resolvedBy)` for manual state correction. The runtime validates every patched key against the graph state schema before resolving the pending interrupt, so invalid edits fail without mutating the interrupt.

Normal input and approval resumes should continue to use `AgentGraph::resume($runId, ['interrupt_id' => $interruptId, ...])`.

## Queue and retry safety

Delayed continuation jobs are safe to retry. A delayed job no-ops when the run is already `completed`, `cancelled`, or `failed`, or when its interrupt is no longer the pending delay interrupt.

Keep external side effects inside `$context->tasks()->once()` so queue retries do not repeat irreversible work.

## Node retry policies

Use `StateGraph::retry($nodeId, maxAttempts: ..., delayMs: ..., backoff: ..., maxDelayMs: ...)` for transient exceptions such as flaky APIs or temporary network failures. `maxAttempts` includes the first attempt.

Node retry policies are synchronous inside the current graph run. They retry only thrown node exceptions. They do not retry `NodeResult::fail()`, human interrupts, delays, or schema-validation failures.

Retrying can execute node code more than once. Keep irreversible side effects inside `$context->tasks()->once()` with stable task keys and deterministic input hashes. Retry attempts are observable through `GraphNodeRetrying`, `node.retrying` traces, and normalized `node.retrying` run events.

## Superstep fan-out

Static multi-edges, conditional fan-out, and dynamic `Send` run deterministically in one process. They model LangGraph-style supersteps but do not create queue-backed worker parallelism yet.

Every node in the same superstep reads the same base state. Writes are merged only after the frontier finishes. Configure an explicit reducer for any channel that can be written by more than one branch.

`Send` input is local to a target node and is preserved in checkpoint metadata for replay/fork. It is not persisted into graph state unless the node writes it. Parallel interrupts inside one frontier are rejected; put approval, review, or state-edit interrupts after fan-in.

## Replay and fork safety

`AgentGraph::replay()` and `AgentGraph::fork()` create new runs from old checkpoint state. They may execute LLM, API, CRM, payment, email, or webhook nodes again.

Before enabling time travel for a graph in production, wrap every irreversible node side effect in `$context->tasks()->once()` with a stable task key and input hash. Use `AgentGraph::timeTravelChildren($checkpointId)` to audit replay and fork branches created from a source checkpoint.

Replay and fork require the persisted checkpoint or run `graph_version` to match the currently registered graph definition. Register a new graph version when node routing or state semantics change.
