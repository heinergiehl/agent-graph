# Changelog

All notable changes to AgentGraph are documented here.

## 0.16.2 - 2026-09-04

Target: align the stable 0.16 runtime with Laravel AI's corrected multi-step provider loop.

### Changed

- Raised the Laravel AI dependency from the legacy `^0.7 || ^1.0` range to the release-verified `^0.11.2` line. This includes upstream Gemini tool-loop coverage for preserving thought signatures across continuations.
- Updated compatibility fixtures to the public `Decisions|string` Agent contract introduced by Laravel AI 0.10.
- Kept AgentGraph runtime behavior, public runtime signatures, persistence contracts, migrations, and graph artifacts unchanged.

### Upgrade

- General applications should update to `heiner/agent-graph:^0.16.2` together with `laravel/ai:^0.11.2` and restart all PHP processes on one resolved dependency set.
- The release-bound Filament Agentic Chatbot package must pin exact `0.16.2` in its package requirement and release contract.

## 0.16.1 - 2026-09-02

Target: unblock upgrades where a timestamp-renamed RC or package migration already created `agent_graph_node_executions.claim_token`, while the canonical 0.16 migration is still pending.

### Fixed

- The claim-token migration now inspects the configured AgentGraph connection and table before changing schema. A correctly shaped nullable `varchar(26)` column with no default or generated behavior is accepted without issuing another `ADD COLUMN`.
- Existing columns with the wrong type, length, nullability, default, auto-increment, or generated definition fail closed with their observed schema shape; the migration does not coerce or replace them.
- Fresh installations still create and verify the column. Repeated `up()` execution is idempotent for a compatible schema.
- Rollback validates the column before removal. It preserves the column when another recorded timestamp variant of the same published migration owns it, while a fresh migration can still remove the column it introduced.

### Upgrade

- General applications should update to `heiner/agent-graph:^0.16.1`, keep workers stopped, and rerun their normal migration command. No data rewrite or additional migration file is introduced.
- Applications already recorded as successfully migrated on 0.16.0 require no schema change, but should still take the patch before the next deployment.
- The release-bound Filament Agentic Chatbot package must pin exact `0.16.1` in both its package requirement and release contract, then refresh and verify its immutable lock.

## 0.16.0 - 2026-09-02

Target: promote the RC2 runtime to the stable pre-v1 Composer channel after the previously open consuming-plugin delay and accepted-parent-resume integration gates were closed.

### Promoted

- Stable installs now use `heiner/agent-graph:^0.16.0`; existing `^0.15.1` constraints remain on the 0.15 line.
- The stable runtime is unchanged from published RC2 commit `43c570e82ab599b6dec924858160f86ac9e70220`. No new runtime code, public signature, dependency constraint, or migration was added after RC2.
- The published RC2 GitHub matrix passed PHP 8.3/8.4 with Laravel 12/13 and Laravel AI `^0.7`. The stable-promotion checkout separately passed strict Composer validation, Pint, the complete 351-test SDK suite with one optional skip, PHPStan, and the production-dependency audit.
- The verified Filament Agentic Chatbot candidate `08d2e7315d23ddf9368d633beaf021a35788b888` preserves repeated-delay delivery authority and recovers only an exact accepted child resume. The provider-free Agent runtime gate run on that exact commit passed 644 tests and 5,913 assertions. This closes those AgentGraph integration gates without approving the plugin's separate commercial release lifecycle.
- RC1 and RC2 notes remain available as explicitly historical prerelease evidence; stable verification and limits are recorded in the [v0.16.0 release notes](docs/releases/v0.16.0.md).

### Upgrade

- 0.15.1 consumers must still adapt task-attempt and node-claim-token store contracts, apply the additive `claim_token` migration, drain all old processes, and restart the SDK and consuming application together. Never mix 0.15 and 0.16 execution against the same runtime records.
- RC2 consumers need no additional migration or runtime adaptation. General root applications can change the exact prerelease constraint to `^0.16.0`; the release-bound Filament Agentic Chatbot package must pin exact `0.16.0`. Verify the resolved source and rerun consuming-host gates.
- Unknown external outcomes, legacy delay checkpoints without `runtime.wait`, explicit recovery cadence, scheduler due-time enforcement, and the absence of a universal exactly-once guarantee remain unchanged. See [UPGRADE.md](UPGRADE.md).

## 0.16.0-rc.2 - 2026-08-31

Target: recover delivery of committed pending delays. This remains a release candidate for integration testing; 0.15.1 remains the stable release. It includes the 0.16.0-rc.1 hardening and breaking persistence contracts below.

### Fixed

- `recover()` can redeliver a committed pending delay through the currently bound `DelayScheduler` after a failed or lost queue dispatch. It preserves the original interrupt, due time, checkpoint, and run state; it does not re-execute the waiting node or replay its observers.
- Delay redelivery rejects inconsistent checkpoint/interrupt bindings, missing or changed graph versions, and invalid persisted timestamps instead of inventing a continuation.

### Changed

- `DelayScheduler::schedule()` may be called repeatedly for the same run and interrupt by recovery. Custom schedulers must tolerate this and preserve the original due time. See [delay recovery](docs/guides/delay-recovery.md) for the contract and remaining transport responsibilities.

### Upgrade and verification

- No additional migration or public method signature change since 0.16.0-rc.1. Review custom delay schedulers and runtime recovery overrides before adoption. Legacy 0.15.1 delay checkpoints without `runtime.wait` remain an explicit reconciliation boundary.
- Select `heiner/agent-graph:0.16.0-rc.2` explicitly. See the [RC2 release notes](docs/releases/v0.16.0-rc.2.md) for verified scope, remaining gates, and the coordinated plugin upgrade requirements.

## 0.16.0-rc.1 - 2026-08-31

Target: atomic ownership and durable runtime transitions. This is a release candidate for integration testing, not a stable production release. Version 0.15.1 remains the stable release.

### Breaking changes

- `TaskStore::complete()` and `fail()` require the claimed `int $attempt` as their second argument. Custom stores must claim atomically and fence final writes against the matching running attempt.
- `NodeExecutionStore::complete()`, `interrupt()`, and `fail()` require the claimed `string $claimToken` as their second argument. Stale writers raise `TaskClaimLostException` or `NodeExecutionClaimLostException` instead of overwriting another worker's result.
- Added the nullable `claim_token` column through `2026_08_31_010000_add_claim_token_to_agent_graph_node_executions.php`. Publish and run this additive migration before starting 0.16 application processes.
- Removed the protected `GraphRuntime::persistQueuedInterrupt()` hook in favor of `persistSuperstepCheckpoint()` and `notifySuperstepCommitted()`. Review runtime subclasses.
- Ordinary `TaskRunner::once()` and public graph/run/resume method signatures remain unchanged. Stricter graph, thread, and child-run binding rejects previously accepted invalid resume requests.

### Fixed

- Task claims now reject active leases and conflicting input atomically, preserve completed receipts, and use monotonically increasing attempts to fence late completion or failure. A throwing `GraphTaskCompleted` listener no longer changes a completed task to failed.
- Database-backed supersteps commit the checkpoint, writes, interrupt, and waiting run status together before notifying observers.
- Recovery can redrive an initial persisted queue frontier before the first checkpoint. Inconsistent legacy wait state requires reconciliation unless durable records prove the matching resume was accepted; recovery does not infer approval from a successor schedule.
- Queue delivery failure no longer downgrades a successful node result. Persisted peer failures stop further peer dispatch, and late worker failures cannot overwrite run cancellation.
- Database child-run and time-travel queries apply lineage filters before the requested limit, so unrelated newer runs cannot hide matching children.
- `GraphTool` checks the target graph and any configured or supplied thread on resume; its thread resolver also runs for resume requests. `DurableGraphSession` rejects runs outside its graph and thread.
- Terminal Laravel AI streaming `Error` events now raise `AgentStreamException` rather than persisting partial output as success.
- Subgraph resumes reject missing, foreign, or stale child identities and invalid child state before accepting the parent response. Delayed children remain waiting; non-completed children are not mapped as successful output.
- Embedded `GraphDefinition` objects in native subgraphs are registered recursively when defining their parent.

### Upgrade

See [UPGRADE.md](UPGRADE.md) for store signatures, migration commands, and the coordinated rollout checklist. Do not mix 0.15 and 0.16 PHP processes. Existing task attempts need no migration; external effects still require stable keys, provider idempotency where available, and reconciliation of unknown outcomes.

The [0.16 RC1 notes](docs/releases/v0.16.0-rc.1.md) map the audit findings to fixes and record verification evidence and remaining integration gates. RC1 required an explicit `0.16.0-rc.1` constraint; existing `^0.15.1` consumers did not receive it automatically.

## 0.15.1 - 2026-07-12

Target: crash-recoverable resume, cancellation, and queued frontier transitions on the stable 0.15 line.

### Added

- Added `AgentGraphManager::recover()` / `AgentGraph::recover()` for lock-protected recovery of `running` runs from their latest durable checkpoint or an accepted pending-resume recovery marker.

### Hardened

- Resume and state-edit resume now persist interrupt resolution, run status, runtime options, and a bounded recovery marker in one database transaction before continuation starts.
- Exact duplicate resume delivery can recover the accepted transition without resolving the interrupt twice; a different payload remains rejected.
- Cancel now resolves a pending interrupt with a typed `cancelled` response in the same transaction as the terminal run transition.
- Queued-superstep frontier records are persisted atomically before dispatch, and recovery redrives durable pending executions or their continuation aggregation after post-commit dispatch loss.

## 0.15.0 - 2026-06-17

Target: production-grade graph contracts, validation, and machine-readable schema metadata for Laravel workflow products built on AgentGraph.

### Added

- Added `GraphSchemaExporter` as the neutral, exact JSON-schema-like exporter for AgentGraph state schemas, including unions, nullable values, enums, arrays, object properties, and message channels.
- Added GraphManifest v2 with `manifest_version`, neutral node metadata, input/output channels, interrupt capability, and side-effect declarations.
- Added `StateGraph::nodeMeta()`, `nodeChannels()`, `nodeCanInterrupt()`, and `nodeSideEffects()` for SDK-neutral node contract metadata.
- Added validator warnings for terminal paths, conditionals without default routes, and nodes mixing static and conditional outgoing routes.
- Added `GraphValidationReport::issues()`, strict report evaluation, issue counts, and stable machine-readable report arrays.
- Added `agent-graph:validate --strict` and `agent-graph:validate --json` for CI release gates.
- Added `InterruptContract` response schemas and `AgentGraph::resumeContract()` for opt-in validation of slot, approval, and choice interrupt responses before resolving a pending interrupt.

### Changed

- `GraphTool` now derives provider-compatible input schemas from `GraphSchemaExporter` instead of depending on a manifest array shape.
- Structured state schemas with `nullable: true` now accept `null` during runtime state validation.
- Composer branch alias now targets `0.15-dev`.

## 0.14.0 - 2026-06-17

Target: runtime contracts and release-readiness features for Laravel apps and workflow products built on AgentGraph.

### Added

- Added `InterruptContract` for typed human-in-the-loop interrupt payloads, including slot-value, approval, and choice contracts.
- Added `NodeResult::interruptContract()` for emitting typed interrupt contracts while preserving the existing `NodeResult::interrupt()` API.
- Added `GraphManifest` and `GraphDefinition::manifest()` for read-only graph metadata, including state schema, reducers, nodes, edges, conditional routes, and node policies.
- Added `GraphValidator` and `GraphValidationReport` for release-time graph checks such as unknown state schema types, unknown reducers, and unreachable nodes.
- Added `AgentGraphManager::definitions()`, `manifest()`, and `validate()` read APIs.
- Added `agent-graph:validate {graph?}` to validate registered graph definitions from CLI release gates. Empty graph registries now fail unless `--allow-empty` is passed.
- Added `GraphTool::schemaInput()` for explicit public tool input schemas.
- `GraphTool::schema()` now derives optional `input` object properties from a registered graph's state schema, falling back to the previous generic object schema when the graph is not registered.
- Added `composer audit --no-dev` to the package `check` script through `test:security`.

### Changed

- Composer branch alias now targets `0.14-dev`.

## 0.13.0 - 2026-05-31

Target: stable 0.13 release channel for Laravel app consumption without requiring Composer prerelease flags.

### Changed

- Promoted the hardened 0.13 runtime contract from `0.13.0-beta.5` to stable `0.13.0`.
- Updated install and release-channel documentation to use `composer require heiner/agent-graph:^0.13`.

## 0.13.0-beta.5 - 2026-05-30

Target: LangGraph-inspired runtime hardening for public SDK beta usage in Laravel products.

### Added

- Added stricter graph and state validation, including unknown reducer rejection, structured schema validation, and dynamic `goto` / `Send` target checks.
- Added multiple `StateGraph::START` entry node execution as the first deterministic superstep.
- Added per-run runtime options with persisted `max_steps` support for runs, resumes, queued supersteps, and delayed continuations.
- Added provider-compatible tool-name sanitization and validation for `GraphTool` and `DurableGraphTool`.
- Added lazy `DelayScheduler` resolution so Laravel container rebindings are honored after runtime construction.
- Added `agent-graph:doctor` production safety gates with `PASS`, `WARN`, and `FAIL` output for store, database, locks, queues, leases, max steps, and tables.

### Hardened

- Cache locks now fail closed by default when the cache store does not support atomic locks.
- Resume, state-edit resume, cancel, queued continuation, and delayed continuation paths are protected by run locks.
- Interrupt resolution is pending-only and run-scoped across database and in-memory stores.
- Terminal runs reject resume, state-edit resume, and cancel attempts without mutating historical state.
- Replay and fork require persisted graph versions to match the registered graph definition.
- Queue jobs now apply package-level tries, timeout, backoff, and AgentGraph tags.
- Queued supersteps now preserve completed sibling node execution results across worker retry boundaries.
- Runtime invariant migrations add checkpoint and queued node execution uniqueness constraints.

### Documentation

- Updated public API, production, upgrade, roadmap, and reference-source documentation for the hardened v1 contract.

## 0.13.0-beta.4 - 2026-05-27

Target: shell-portable Packagist install documentation for the public 0.13 beta line.

### Changed

- Updated beta install docs from `^0.13@beta` to `~0.13.0@beta`, which avoids Windows `composer.bat` caret escaping while still tracking the 0.13 beta line.

## 0.13.0-beta.3 - 2026-05-27

Target: Packagist readiness for the public 0.13 beta line.

### Changed

- Documented the beta Composer install command as `composer require heiner/agent-graph:^0.13@beta`.
- Added Composer author metadata for the Packagist package page.
- Clarified release smoke-test guidance for pre-Packagist VCS installs and post-Packagist normal installs.

### Packaging

- Excluded internal handoff, sandbox validation, and agent working-plan artifacts from Composer distribution archives.

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
