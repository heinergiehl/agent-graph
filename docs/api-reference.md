# AgentGraph API Reference

This document describes the intended v1 public API surface. APIs marked experimental are public but may receive compatibility-preserving hardening before a later stable time-travel release.

## Graph Definition

### `StateGraph`

- `StateGraph::make(string $key, string $version = '1'): StateGraph` creates a graph builder. The key identifies the graph; the version is persisted on runs and checkpoints.
- `state(array $schema): self` defines state channels and simple schema types such as `string`, `string|null`, `int`, `bool`, `array`, `messages`, and `mixed`.
- `reducer(string $channel, mixed $reducer): self` configures channel reducers. Built-ins include `append`, `merge`, `messages`/`add_messages`, and `max`/`max_confidence`.
- `node(string $id, callable|string $node): self` registers an invokable node class, callable, or `Node` implementation.
- `edge(string $from, string $to): self` registers a static edge.
- `conditional(string $from, Closure $resolver, array $routes): self` registers conditional routing.
- `compile(): GraphDefinition` validates and returns an immutable graph definition.

Errors: invalid graph structure throws `InvalidArgumentException`.

Stability: stable.

### `GraphDefinition`

Compiled definitions expose `key()`, `version()`, `schema()`, `reducers()`, `node()`, `nodes()`, `hasNode()`, `hasEndpoint()`, `entryNode()`, `resolveNext()`, and `successorsOf()`.

Errors: unknown nodes, endpoints, or invalid graph structure throw `InvalidArgumentException`.

Stability: stable for read-only metadata and endpoint helpers.

## Runtime Facade

All methods are available through the `AgentGraph` facade and `AgentGraphManager`.

- `define(StateGraph|GraphDefinition $graph): GraphDefinition` registers a graph.
- `graph(string $key): PendingGraphRun` creates a pending run builder for a registered graph.
- `resume(string $runId, array $payload = [], ?callable $onEvent = null, bool $collectEvents = false): RunResult` resumes a run. If a pending interrupt exists, `interrupt_id` must match it.
- `resumeWithStateEdit(string $runId, string $interruptId, array $statePatch, ?string $resolvedBy = null, ?callable $onEvent = null, bool $collectEvents = false): RunResult` resolves a `state_edit` interrupt after strict schema validation.
- `cancel(string $runId, array $meta = []): RunResult` marks a run cancelled.
- `inspect(string $runId, bool $withHistory = false, bool $withTraces = false): ?RunSnapshot` returns a read-only run snapshot without mutating runtime state.
- `timeline(string $runId, bool $includeState = false, bool $includeDiff = true): ?RunTimeline` returns ordered, read-only timeline steps built from checkpoints, writes, interrupts, failures, and state diffs.
- `runs(array $filters = [], int $limit = 50): array` lists recent runs. Supported filters are `status`, `thread_id`, `graph_key`, and `graph_version`.
- `tool(string $graphKey): GraphTool` exposes a graph as a Laravel AI tool.

Errors: missing runs, missing graph definitions, stale interrupt IDs, schema validation failures, and graph version mismatches throw `RuntimeException` or `InvalidArgumentException` depending on the failure.

Stability: stable.

### Experimental Time Travel

- `checkpoint(string $checkpointId, bool $withWrites = false): ?CheckpointSnapshot` returns a specific checkpoint snapshot.
- `replay(string $checkpointId, ?string $threadId = null, array $meta = [], ?callable $onEvent = null, bool $collectEvents = false): RunResult` creates a new run from a checkpoint and continues through recorded `next_nodes`.
- `fork(string $checkpointId, array $statePatch = [], ?string $threadId = null, ?string $asNode = null, array $meta = [], ?callable $onEvent = null, bool $collectEvents = false): RunResult` creates a new run from a checkpoint with a reducer-aware state patch.
- `timeTravelChildren(string $checkpointId, int $limit = 50): array` lists replay and fork runs created from a source checkpoint.

Errors: missing checkpoints, missing graph definitions, invalid state patches, unknown fork endpoints, and graph version mismatches throw `RuntimeException` or `InvalidArgumentException`.

Stability: experimental public API.

## Node Runtime

### `Node`

Nodes implement:

```php
public function __invoke(NodeContext $context): NodeResult;
```

Nodes may also be plain invokable callables. Returning an array is treated as `NodeResult::write($array)`.

Stability: stable.

### `NodeContext`

Node context exposes:

- `state(?string $key = null, mixed $default = null): mixed`
- `runId(): string`
- `threadId(): string`
- `nodeId(): string`
- `checkpointId(): ?string`
- `graphMeta(): array`
- `memory(): MemoryStore`
- `traces(): TraceStore`
- `tasks(): TaskRunner`

Use `tasks()->once()` for external side effects that must remain idempotent during retries, replay, or fork.

Stability: stable.

### `NodeResult`

- `NodeResult::write(array $writes): NodeResult`
- `NodeResult::goto(string $node, array $writes = []): NodeResult`
- `NodeResult::send(string $node, array $input = [], array $writes = []): NodeResult`
- `NodeResult::sendMany(array $sends, array $writes = []): NodeResult`
- `NodeResult::interrupt(string $type, array $payload = [], array $writes = []): NodeResult`
- `NodeResult::end(array $writes = []): NodeResult`
- `NodeResult::fail(string $message, array $meta = []): NodeResult`
- `withMeta(array $meta): self`
- `withNodeMeta(array $meta): self` stores generic inspectable node metadata under `meta.node`.
- `skipped(): self` marks the node metadata status as `skipped`.

Accessor methods include `status()`, `writes()`, `nextNode()`, `sends()`, `interruptType()`, `interruptPayload()`, `failureMessage()`, and `meta()`.

State writes are validated against graph state schema before persistence. Invalid node writes fail the run.

Stability: stable.

### `Send`

`Send::to(string $node, array $input = [], array $meta = []): Send` schedules dynamic fan-out to a target node.

Methods: `node()`, `input()`, `meta()`, and `toArray()`.

Send input is overlaid only for that node invocation and is not persisted to graph state unless the target node writes it. If multiple nodes in one superstep write the same channel, that channel must have an explicit reducer. The built-in `messages` state type keeps its automatic add-messages reducer.

Stability: stable.

## Results and Snapshots

### `PendingGraphRun`

Returned by `AgentGraph::graph($key)`.

Methods: `thread()`, `input()`, `meta()`, `onEvent()`, `collectEvents()`, and `run()`.

`onEvent(callable $listener)` receives `RunEvent` objects synchronously for that run. `collectEvents(bool $collect = true)` stores the same normalized events on the returned `RunResult`.

Stability: stable.

### `RunResult`

Returned by run, resume, cancel, replay, and fork operations.

Methods: `runId()`, `threadId()`, `status()`, `completed()`, `interrupted()`, `failed()`, `cancelled()`, `error()`, `resumeAt()`, `state()`, `interrupt()`, and `events()`.

`events()` returns an array of `RunEvent` objects when collection was enabled, otherwise an empty array.

Stability: stable.

### `RunEvent`

Returned through `PendingGraphRun::onEvent()` and `RunResult::events()`.

Methods: `type()`, `runId()`, `threadId()`, `graphKey()`, `nodeId()`, `payload()`, `timestamp()`, and `toArray()`.

Event types are AgentGraph workflow observations such as `run.started`, `run.resumed`, `node.started`, `node.completed`, `node.failed`, `stream.delta`, `checkpoint.created`, `interrupt.created`, `run.completed`, `run.failed`, and `run.cancelled`.

Run events are callback/collection observations only. AgentGraph core does not expose SSE, Vercel AI SDK protocols, HTTP responses, provider internals, or a replacement for Laravel AI model streaming.

Stability: stable.

### `RunSnapshot`

Returned by `inspect()`.

Methods: `run()`, `runId()`, `threadId()`, `graphKey()`, `graphVersion()`, `status()`, `state()`, `checkpoint()`, `checkpoints()`, `writes()`, `interrupt()`, `traces()`, `error()`, `meta()`, and `toRunResult()`.

Stability: stable.

### `RunTimeline`

Returned by `timeline()`.

Methods: `run()`, `runId()`, `threadId()`, `graphKey()`, `graphVersion()`, `status()`, `steps()`, and `toArray()`.

Timeline steps are ordered by checkpoint step. Full `state_before` and `state_after` payloads are omitted unless `includeState` is true. `state_diff` is included by default and uses the same redaction and string truncation policy as traces.

Stability: stable.

### `RunTimelineStep`

Returned inside `RunTimeline::steps()`.

Methods: `step()`, `nodeId()`, `nodeIds()`, `status()`, `checkpointId()`, `previousCheckpointId()`, `writes()`, `interrupt()`, `error()`, `meta()`, `stateBefore()`, `stateAfter()`, `stateDiff()`, and `toArray()`.

Statuses are inferred from explicit node metadata, interrupts, failed latest checkpoints, or completed checkpoints.

Stability: stable.

### `StateDiff`

Returned by `RunTimelineStep::stateDiff()` when diffs are included.

Methods: `added()`, `changed()`, `removed()`, and `toArray()`.

Stability: stable.

### `CheckpointSnapshot`

Returned by `checkpoint()`.

Methods: `checkpoint()`, `checkpointId()`, `runId()`, `threadId()`, `graphKey()`, `graphVersion()`, `parentCheckpointId()`, `step()`, `state()`, `stateBefore()`, `stateAfter()`, `nextNodes()`, `completedNodes()`, `meta()`, and `writes()`.

Stability: experimental public API.

## Laravel AI Integration

### `AgentNode`

`AgentNode::make(string $id)` creates a node wrapping a Laravel AI agent.

Configuration methods: `agent()`, `prompt()`, `attachments()`, `stream()`, `provider()`, `model()`, `timeout()`, `writeTextTo()`, `writeUsageTo()`, and `writeMetaTo()`.

`stream()` delegates to Laravel AI's public `Agent::stream()` contract. Text deltas still dispatch `GraphStreamDelta`; when run-event observation is enabled, the same delta payload is also normalized as `stream.delta`.

Errors: missing or invalid agent configuration and non-string prompts throw `RuntimeException`.

Stability: stable.

### `GraphTool`

`AgentGraph::tool(string $graphKey)` exposes a graph as a Laravel AI tool.

Configuration methods: `name()`, `description()`, and `thread()`.

Tool responses are JSON with `status`, `run_id`, `thread_id`, `state`, `interrupt`, and `error`. Tool exceptions are converted into a failed JSON response.

Stability: stable.

## Store Contracts

Production adapters may implement these contracts:

- `RunStore`: create, find, list, list time-travel children, update.
- `CheckpointStore`: create, find, latest for run, list for run.
- `InterruptStore`: create, find, list for run, pending for run, resolve.
- `WriteStore`: create many, list for checkpoint, list for run.
- `TraceStore`: record, list for run.
- `TaskStore`: find by key, start, complete, fail.
- `MemoryStore`: write, read, search.

Adapters must preserve JSON-serializable arrays for stored payloads and return decoded arrays matching database and in-memory store shapes.

Stability: stable, with v1 contract changes documented in `UPGRADE.md`.

## Errors and Compatibility

- Unknown state keys in run input, state-edit resume, fork patches, and node writes throw or fail strictly.
- Normal `resume()` remains compatible with extra unknown payload keys, but known schema keys are type-validated.
- Replay and fork require persisted `graph_version` to match the currently registered graph definition.
- Supersteps store one checkpoint per frontier and preserve dynamic `Send` schedules in checkpoint metadata without a database migration.
- Parallel interrupts inside a multi-node frontier fail the run with a clear error; single-node interrupts keep existing resume behavior.
- Run-event observation is additive and does not change `GraphStreamDelta`, Laravel AI `StreamableAgentResponse`, `GraphTool` JSON shape, or provider behavior.
- No new database migrations are required for v1 hardening, supersteps, or experimental time travel.
