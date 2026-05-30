# AgentGraph API Reference

This document describes the public API surface exposed by the 0.13 beta and intended for v1 stabilization. APIs marked experimental are public but may receive compatibility-preserving hardening before a later stable time-travel release.

## Graph Definition

### `StateGraph`

- `StateGraph::make(string $key, string $version = '1'): StateGraph` creates a graph builder. The key identifies the graph; the version is persisted on runs and checkpoints.
- `state(array|StateSchema $schema): self` defines state channels and schema types such as `string`, `string|null`, `int`, `bool`, `array`, `messages`, `mixed`, or structured `StateSchema` definitions. Unknown schema types throw instead of being treated as permissive `mixed`.
- `reducer(string $channel, mixed $reducer): self` configures channel reducers. Built-ins include `append`, `merge`, `messages`/`add_messages`, and `max`/`max_confidence`.
- `node(string $id, callable|string $node): self` registers an invokable node class, callable, or `Node` implementation.
- `edge(string $from, string $to): self` registers a static edge.
- `conditional(string $from, Closure $resolver, array $routes): self` registers conditional routing.
- `retry(string $nodeId, int $maxAttempts = 3, int $delayMs = 0, float $backoff = 1.0, ?int $maxDelayMs = null, ?callable $when = null): self` configures retry for thrown exceptions from one node.
- `timeout(string $nodeId, float $seconds): self` configures portable wall-clock timeout detection for one node.
- `concurrency(string $nodeId, int $limit = 1, ?string $key = null): self` configures lock-backed node concurrency.
- `compile(): GraphDefinition` validates and returns an immutable graph definition.

Errors: invalid graph structure throws `InvalidArgumentException`.

Stability: stable.

### `GraphDefinition`

Compiled definitions expose `key()`, `version()`, `schema()`, `reducers()`, `node()`, `nodes()`, `nodePolicy()`, `nodePolicies()`, `hasNode()`, `hasEndpoint()`, `entryNode()`, `resolveNext()`, and `successorsOf()`.

Errors: unknown nodes, endpoints, or invalid graph structure throw `InvalidArgumentException`.

Stability: stable for read-only metadata and endpoint helpers.

### `StateSchema`

`StateSchema::make()` creates a builder for structured schema definitions. Methods include `string()`, `integer()`, `boolean()`, `array()`, `object()`, `enum()`, `messages()`, `channel()`, and `toArray()`.

String schemas remain supported for backward compatibility. Supported primitive types are `mixed`, `null`, `string`, `int`, `integer`, `float`, `double`, `bool`, `boolean`, `array`, `messages`, and `object`. Supported structured types are `enum`, `array`, and `object`; structured definitions without `type` remain compatible as `mixed`.

Structured object validation validates provided nested properties and enum values. Structured arrays require a PHP list and validate every item against the configured `items` schema.

Stability: additive beta API.

### `NodePolicy`, `RetryPolicy`, `TimeoutPolicy`, and `ConcurrencyPolicy`

`GraphDefinition::nodePolicy($nodeId)` returns a `NodePolicy`. Unknown nodes return the default empty policy for read-only inspection; policy configuration for unknown nodes fails during graph compile.

`RetryPolicy` exposes `maxAttempts()`, `delayMs()`, `backoff()`, `maxDelayMs()`, `delayForAttempt()`, and `shouldRetry()`.

`TimeoutPolicy` exposes `seconds()`. Timeout checks are portable wall-clock checks around node execution and do not terminate PHP execution mid-call.

`ConcurrencyPolicy` exposes `limit()` and `key()`. The default runtime enforces `limit: 1` with AgentGraph's `LockProvider`; higher limits are reserved for custom limiters/adapters.

`maxAttempts` is the total attempt count including the first attempt. `delayMs` is the first retry delay, `backoff` multiplies later delays, and `maxDelayMs` caps retry delays when set. The optional `when(Throwable $exception, int $attempt, NodeContext $context): bool` predicate can stop retrying before attempts are exhausted.

Retry policies apply only to thrown node exceptions. They do not retry `NodeResult::fail()`, interrupts, delays, or schema-validation failures. Retry attempts emit `node.retrying` events/traces, and successful retried writes include `runtime.retry` metadata.

Stability: stable.

## Runtime Facade

All methods are available through the `AgentGraph` facade and `AgentGraphManager`.

- `define(StateGraph|GraphDefinition $graph): GraphDefinition` registers a graph.
- `graph(string $key): PendingGraphRun` creates a pending run builder for a registered graph. Calling `run()` on the builder intentionally creates a new run.
- `resume(string $runId, array $payload = [], ?callable $onEvent = null, bool $collectEvents = false): RunResult` resumes an active run. If a pending interrupt exists, `interrupt_id` must match it. Terminal runs cannot be resumed.
- `resumeStrict(string $runId, array $payload = [], ?callable $onEvent = null, bool $collectEvents = false): RunResult` resumes a run while rejecting unknown state keys.
- `resumeWithStateEdit(string $runId, string $interruptId, array $statePatch, ?string $resolvedBy = null, ?callable $onEvent = null, bool $collectEvents = false): RunResult` resolves a `state_edit` interrupt on an active run after strict schema validation.
- `cancel(string $runId, array $meta = []): RunResult` marks an active `running`, `interrupted`, or `delayed` run cancelled.
- `inspect(string $runId, bool $withHistory = false, bool $withTraces = false): ?RunSnapshot` returns a read-only run snapshot without mutating runtime state.
- `timeline(string $runId, bool $includeState = false, bool $includeDiff = true): ?RunTimeline` returns ordered, read-only timeline steps built from checkpoints, writes, interrupts, failures, and state diffs.
- `runs(array $filters = [], int $limit = 50): array` lists recent runs. Supported filters are `status`, `thread_id`, `graph_key`, and `graph_version`.
- `childRuns(string $parentRunId, int $limit = 50): array` lists runs whose metadata points to a parent run under `meta.parent.run_id`.
- `tasks(array $filters = [], int $limit = 50): array` lists idempotent task records for inspection. Supported filters are `run_id`, `node_id`, `checkpoint_id`, and `status`.
- `nodeExecutions(string $runId): array` lists queued-superstep node execution records.
- `expireInterrupts(mixed $now = null): int` expires pending interrupts whose `expires_at` is due.
- `tool(string $graphKey): GraphTool` exposes a graph as a Laravel AI tool.
- `durableTool(string $graphKey): DurableGraphTool` exposes an active-thread durable graph tool for Laravel AI.
- `session(string $graphKey, string $threadId): DurableGraphSession` creates an active-thread workflow session.
- `memory(): MemoryManager` returns memory writer/export/delete helpers.
- `migrationsPath(): string` returns the package migration path.

Errors: missing runs, missing graph definitions, stale interrupt IDs, terminal resume/cancel attempts, schema validation failures, and graph version mismatches throw `RuntimeException` or `InvalidArgumentException` depending on the failure.

Stability: stable.

### Experimental Time Travel

- `checkpoint(string $checkpointId, bool $withWrites = false): ?CheckpointSnapshot` returns a specific checkpoint snapshot.
- `replay(string $checkpointId, ?string $threadId = null, array $meta = [], ?callable $onEvent = null, bool $collectEvents = false): RunResult` creates a new run from a checkpoint and continues through recorded `next_nodes`.
- `fork(string $checkpointId, array $statePatch = [], ?string $threadId = null, ?string $asNode = null, array $meta = [], ?callable $onEvent = null, bool $collectEvents = false): RunResult` creates a new run from a checkpoint with a reducer-aware state patch.
- `timeTravelChildren(string $checkpointId, int $limit = 50): array` lists replay and fork runs created from a source checkpoint.

Replay and fork preserve `meta.time_travel` and also store `meta.parent` with `relationship` set to `replay` or `fork`.

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
- `hasResumePayload(): bool`
- `resumePayload(): array`
- `interruptId(): ?string`

Use `tasks()->once()` for external side effects that must remain idempotent during retries, replay, or fork.

`hasResumePayload()`, `resumePayload()`, and `interruptId()` are populated only for the node invocation that resumes a pending interrupt. Normal node starts return `false`, an empty payload, and `null`.

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
- `withInterruptPolicy(InterruptPolicy $policy): self` attaches interrupt expiry/resolver metadata.

Accessor methods include `status()`, `writes()`, `nextNode()`, `sends()`, `interruptType()`, `interruptPayload()`, `failureMessage()`, and `meta()`.

State writes are validated against graph state schema before persistence. Invalid node writes fail the run.

Standard node metadata keys under `meta.node` are `id`, `label`, `type`, `status`, `category`, `source`, and `description`. Timeline and inspection APIs expose this metadata without requiring apps to inspect package tables.

Stability: stable.

### `SubgraphNode`

`SubgraphNode::make(string $id, string|GraphDefinition $graph)` creates a node that runs another graph as a child run.

Configuration methods: `isolated(?Closure $input = null, ?Closure $output = null)`, `shared(?Closure $input = null, ?Closure $output = null)`, and `mapped(?Closure $input = null, ?Closure $output = null)`.

Child runs are persisted as normal runs with `run.meta.parent`. Child interrupts bubble to the parent as `subgraph` interrupts containing `child_run_id`, `child_interrupt_id`, and the child interrupt payload. Resuming the parent with those child identifiers resumes the child first, then maps the child state back into the parent writes.

Stability: additive beta API.

### `Send`

`Send::to(string $node, array $input = [], array $meta = []): Send` schedules dynamic fan-out to a target node.

Methods: `node()`, `input()`, `meta()`, and `toArray()`.

Send input is overlaid only for that node invocation and is not persisted to graph state unless the target node writes it. If multiple nodes in one superstep write the same channel, that channel must have an explicit reducer. The built-in `messages` state type keeps its automatic add-messages reducer.

Stability: stable.

## Results and Snapshots

### `PendingGraphRun`

Returned by `AgentGraph::graph($key)`.

Methods: `thread()`, `input()`, `meta()`, `parent()`, `onEvent()`, `collectEvents()`, and `run()`.

`parent(string $runId, ?string $checkpointId = null, ?string $nodeId = null, int $depth = 1, string $relationship = 'child')` stores generic parent lineage under `run.meta.parent`.

`onEvent(callable $listener)` receives `RunEvent` objects synchronously for that run. `collectEvents(bool $collect = true)` stores the same normalized events on the returned `RunResult`.

Stability: stable.

### `RunResult`

Returned by run, resume, cancel, replay, and fork operations.

Methods: `runId()`, `threadId()`, `status()`, `completed()`, `interrupted()`, `failed()`, `cancelled()`, `error()`, `meta()`, `resumeAt()`, `state()`, `interrupt()`, and `events()`.

`events()` returns an array of `RunEvent` objects when collection was enabled, otherwise an empty array.

When `agent-graph.execution.mode` is `queued_supersteps`, `run()` and `resume()` usually return `status() === 'running'` after scheduling node jobs. Read the final status through `inspect()` after workers process `NodeExecutionJob` and `ContinueSuperstepJob`.

Stability: stable.

### `RunEvent`

Returned through `PendingGraphRun::onEvent()` and `RunResult::events()`.

Methods: `type()`, `runId()`, `threadId()`, `graphKey()`, `nodeId()`, `payload()`, `timestamp()`, and `toArray()`.

Event types are AgentGraph workflow observations such as `run.started`, `run.resumed`, `node.started`, `node.retrying`, `node.completed`, `node.failed`, `stream.delta`, `checkpoint.created`, `interrupt.created`, `run.completed`, `run.failed`, and `run.cancelled`.

Run events are callback/collection observations only. AgentGraph core does not expose SSE, Vercel AI SDK protocols, HTTP responses, provider internals, or a replacement for Laravel AI model streaming.

Stability: stable.

### `RunSnapshot`

Returned by `inspect()`.

Methods: `run()`, `runId()`, `threadId()`, `graphKey()`, `graphVersion()`, `status()`, `state()`, `checkpoint()`, `checkpoints()`, `writes()`, `interrupt()`, `traces()`, `error()`, `meta()`, `parent()`, and `toRunResult()`.

`parent()` returns normalized `run.meta.parent` lineage or `null` when a run has no parent.

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

Configuration methods: `agent()`, `prompt()`, `attachments()`, `stream()`, `provider()`, `model()`, `timeout()`, `writeTextTo()`, `writeUsageTo()`, `writeMetaTo()`, `writeStructuredTo()`, `writeToolCallsTo()`, `writeToolResultsTo()`, `writeStepsTo()`, `writeStreamEventsTo()`, and `onTextDelta()`.

`stream()` delegates to Laravel AI's public `Agent::stream()` contract. Text deltas still dispatch `GraphStreamDelta`; when run-event observation is enabled, the same delta payload is also normalized as `stream.delta`. `onTextDelta(function (string $delta, array $payload, NodeContext $context, TextDelta $event): void {})` receives the same streamed text delta directly; callbacks may declare fewer parameters.

Errors: missing or invalid agent configuration and non-string prompts throw `RuntimeException`.

Stability: stable.

### `DurableGraphSession`

`AgentGraph::session(string $graphKey, string $threadId)` returns an active-thread workflow session.

Methods: `start(array $input = [], array $meta = [])`, `run(array $input = [], array $meta = [])`, `resume(array $payload = [], bool $strict = false)`, `cancel(array $meta = [])`, `status()`, and `activeRun()`.

`run()` returns the active interrupted/delayed/running run for the graph+thread when one exists; otherwise it starts a new run. The active-run lookup and start path are protected by an AgentGraph session lock. `start()` always creates a fresh run.

Stability: additive beta API.

### `GraphTool`

`AgentGraph::tool(string $graphKey)` exposes a graph as a Laravel AI tool.

Configuration methods: `name()`, `description()`, `thread()`, `input()`, `output()`, and `meta()`.

`input(Closure $mapper)` maps a Laravel AI tool `Request` into graph input for new runs and resume payloads. `meta(Closure|array $meta)` adds metadata to new runs only. `output(Closure $mapper)` maps the final `RunResult` and original `Request` into the tool response.

Default tool responses are JSON with `status`, `run_id`, `thread_id`, `state`, `interrupt`, and `error`. Tool exceptions are converted into a failed JSON response.

Stability: stable.

### `DurableGraphTool`

`AgentGraph::durableTool(string $graphKey)` exposes `DurableGraphSession` as a Laravel AI `Tool`.

Configuration methods: `name()`, `description()`, `thread()`, and `strictResume()`.

The default response is JSON with `status`, `run_id`, `thread_id`, `state`, `interrupt`, `summary`, and `error`. The existing `GraphTool` response shape is unchanged.

Stability: additive beta API.

## Memory Manager

`AgentGraph::memory()` returns a `MemoryManager`.

Methods: `writeExtracted(MemoryScope $scope, string $namespace, string $text, array $meta = [])`, `export(MemoryScope $scope, ?string $namespace = null)`, `deleteScope()`, `deleteNamespace()`, `deleteKey()`, `embeddings()`, and `vectors()`.

Default bindings are deterministic and do not require vector infrastructure. Applications can replace `MemoryExtractor`, `EmbeddingGenerator`, or `VectorMemoryStore`. Laravel AI can generate embeddings; AgentGraph stores vectors only through the configured vector store. `PgvectorMemoryStore` and `stubs/pgvector-memory-migration.stub` are optional experimental pgvector starting points.

Stability: additive beta API.

## Store Contracts

Production adapters may implement these contracts:

- `RunStore`: create, find, list, latest for thread+graph, list child runs, list time-travel children, update.
- `CheckpointStore`: create, find, latest for run, list for run.
- `InterruptStore`: create, find, list for run, pending for run, resolve, expire pending.
- `WriteStore`: create many, list for checkpoint, list for run.
- `TraceStore`: record, list for run.
- `TaskStore`: find by key, list, start, complete, fail.
- `LeasingTaskStore`: extends task storage with active lease inspection.
- `MemoryStore`: write, read, search, export/delete privacy APIs.
- `EnumerableMemoryStore`: extends `MemoryStore` with `listNamespace(array $scopes, string $namespace): array`.
- `NodeExecutionStore`: schedule, find, claim, complete, interrupt, fail, and list queued-superstep node execution records.
- `MemoryExtractor`, `EmbeddingGenerator`, and `VectorMemoryStore`: optional agentic/vector memory extension contracts.
- `DelayScheduler`: schedule delayed resume payloads for delay interrupts.

Adapters must preserve JSON-serializable arrays for stored payloads and return decoded arrays matching database and in-memory store shapes.

The package database stores use `agent-graph.database.connection`, which maps to `AGENT_GRAPH_DB_CONNECTION`. The same configured connection is used by package migrations, runtime transactions, `agent-graph:doctor`, `agent-graph:prune`, and the optional `PgvectorMemoryStore`.

Production runs require a cache store that supports atomic locks. Keep `AGENT_GRAPH_LOCK_FAIL_WITHOUT_PROVIDER=true` outside local throwaway tests.

The default `DelayScheduler` dispatches `ContinueDelayedGraphJob` on the configured AgentGraph execution queue connection and queue; Laravel applications can bind their own scheduler implementation.

Stability: stable, with v1 contract changes documented in `UPGRADE.md`.

## Errors and Compatibility

- Unknown state keys in run input, state-edit resume, fork patches, and node writes throw or fail strictly.
- Unknown schema definition types throw instead of being silently accepted.
- Structured array schemas require list values and validate every item against `items`.
- Run errors use a structured payload with `message`, `exception_class`, `code`, `previous`, and optional `details`/`meta`.
- Normal `resume()` remains compatible with extra unknown payload keys, but known schema keys are type-validated.
- `resumeStrict()` rejects unknown state keys for public endpoints that need stricter input control.
- Terminal `completed`, `cancelled`, and `failed` runs cannot be resumed, state-edit resumed, or cancelled again.
- Replay and fork require persisted `graph_version` to match the currently registered graph definition.
- Supersteps store one checkpoint per frontier and preserve dynamic `Send` schedules in checkpoint metadata.
- `queued_supersteps` is opt-in and uses Laravel Queue jobs for worker-backed node execution. Sync execution remains the default.
- Parallel interrupts inside a multi-node frontier fail the run with a clear error; single-node interrupts keep existing resume behavior.
- Per-node retry policies are synchronous inside the current runtime. They retry thrown node exceptions only and may repeat side effects unless nodes use `tasks()->once()`.
- Run-event observation is additive and does not change `GraphStreamDelta`, Laravel AI `StreamableAgentResponse`, `GraphTool` JSON shape, or provider behavior.
- GraphTool mapping hooks are adapter conveniences; durable active-thread semantics belong in `DurableGraphSession` or `DurableGraphTool`.
- Parent/child run metadata is stored under `run.meta.parent` for inspection and lineage. `SubgraphNode` uses this same lineage.
- The hardening migrations add interrupt expiry and queued node execution records. Existing published migrations remain valid; apps must run the additive migrations.
