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
- `resume(string $runId, array $payload = []): RunResult` resumes a run. If a pending interrupt exists, `interrupt_id` must match it.
- `resumeWithStateEdit(string $runId, string $interruptId, array $statePatch, ?string $resolvedBy = null): RunResult` resolves a `state_edit` interrupt after strict schema validation.
- `cancel(string $runId, array $meta = []): RunResult` marks a run cancelled.
- `inspect(string $runId, bool $withHistory = false, bool $withTraces = false): ?RunSnapshot` returns a read-only run snapshot without mutating runtime state.
- `runs(array $filters = [], int $limit = 50): array` lists recent runs. Supported filters are `status`, `thread_id`, `graph_key`, and `graph_version`.
- `tool(string $graphKey): GraphTool` exposes a graph as a Laravel AI tool.

Errors: missing runs, missing graph definitions, stale interrupt IDs, schema validation failures, and graph version mismatches throw `RuntimeException` or `InvalidArgumentException` depending on the failure.

Stability: stable.

### Experimental Time Travel

- `checkpoint(string $checkpointId, bool $withWrites = false): ?CheckpointSnapshot` returns a specific checkpoint snapshot.
- `replay(string $checkpointId, ?string $threadId = null, array $meta = []): RunResult` creates a new run from a checkpoint and continues through recorded `next_nodes`.
- `fork(string $checkpointId, array $statePatch = [], ?string $threadId = null, ?string $asNode = null, array $meta = []): RunResult` creates a new run from a checkpoint with a reducer-aware state patch.
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
- `NodeResult::interrupt(string $type, array $payload = [], array $writes = []): NodeResult`
- `NodeResult::end(array $writes = []): NodeResult`
- `NodeResult::fail(string $message, array $meta = []): NodeResult`
- `withMeta(array $meta): self`

Accessor methods include `status()`, `writes()`, `nextNode()`, `interruptType()`, `interruptPayload()`, `failureMessage()`, and `meta()`.

State writes are validated against graph state schema before persistence. Invalid node writes fail the run.

Stability: stable.

## Results and Snapshots

### `RunResult`

Returned by run, resume, cancel, replay, and fork operations.

Methods: `runId()`, `threadId()`, `status()`, `completed()`, `interrupted()`, `failed()`, `cancelled()`, `error()`, `resumeAt()`, `state()`, and `interrupt()`.

Stability: stable.

### `RunSnapshot`

Returned by `inspect()`.

Methods: `run()`, `runId()`, `threadId()`, `graphKey()`, `graphVersion()`, `status()`, `state()`, `checkpoint()`, `checkpoints()`, `writes()`, `interrupt()`, `traces()`, `error()`, `meta()`, and `toRunResult()`.

Stability: stable.

### `CheckpointSnapshot`

Returned by `checkpoint()`.

Methods: `checkpoint()`, `checkpointId()`, `runId()`, `threadId()`, `graphKey()`, `graphVersion()`, `parentCheckpointId()`, `step()`, `state()`, `nextNodes()`, `completedNodes()`, `meta()`, and `writes()`.

Stability: experimental public API.

## Laravel AI Integration

### `AgentNode`

`AgentNode::make(string $id)` creates a node wrapping a Laravel AI agent.

Configuration methods: `agent()`, `prompt()`, `attachments()`, `stream()`, `provider()`, `model()`, `timeout()`, `writeTextTo()`, `writeUsageTo()`, and `writeMetaTo()`.

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
- No new database migrations are required for v1 hardening or experimental time travel.
