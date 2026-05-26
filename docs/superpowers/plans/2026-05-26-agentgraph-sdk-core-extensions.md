# AgentGraph SDK Core Extensions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the next generic AgentGraph SDK features that reduce plugin-specific code without adding chatbot, Filament, provider, or product-specific behavior to core.

**Architecture:** Add small contracts and read models around the existing runtime and stores. Keep execution semantics in `GraphRuntime`, keep read-only inspection in inspector/store APIs, and keep Laravel AI convenience behavior in `AgentNode` and `GraphTool` adapters.

**Tech Stack:** PHP 8.3, Laravel package conventions, Pest, Orchestra Testbench, existing AgentGraph runtime/store contracts.

---

## Current Working Tree Note

This plan was created while candidate implementation changes were already present in the working tree for resume context, delay scheduling, enumerable memory, streaming callbacks, and structured errors. Do not revert those changes. First validate, tighten, document, and test them.

## File Map

- `src/Runtime/NodeContext.php`: public node runtime context, including resume context accessors.
- `src/Runtime/GraphRuntime.php`: execution semantics, resume context propagation, delay scheduling, runtime error persistence.
- `src/Runtime/RuntimeError.php`: normalized persisted/returned runtime error payloads.
- `src/Contracts/DelayScheduler.php`: replaceable delay scheduling contract.
- `src/Support/QueueDelayScheduler.php`: default queue-backed delay scheduler.
- `src/Contracts/EnumerableMemoryStore.php`: optional read/list memory contract.
- `src/Persistence/DatabaseMemoryStore.php`: database implementation of enumerable memory.
- `src/Persistence/InMemoryMemoryStore.php`: in-memory implementation of enumerable memory.
- `src/Runtime/RunInspector.php`: timeline error and metadata read model.
- `src/LaravelAi/AgentNode.php`: Laravel AI node streaming callback convenience API.
- `src/LaravelAi/GraphTool.php`: graph-as-tool mapping and error output.
- `src/Contracts/TaskStore.php`: future task read listing surface.
- `src/Persistence/DatabaseTaskStore.php`: future database task inspection implementation.
- `src/Persistence/InMemoryTaskStore.php`: future in-memory task inspection implementation.
- `docs/api-reference.md`: stable public API reference.
- `README.md`: concise user-facing examples.
- `ROADMAP.md`: release scope and deferred features.
- `CHANGELOG.md`: public behavior notes.
- `UPGRADE.md`: upgrade notes for any changed public behavior.

---

### Task 1: Validate In-Progress Candidate Changes

**Files:**
- Read: `src/Runtime/NodeContext.php`
- Read: `src/Runtime/GraphRuntime.php`
- Read: `src/Runtime/RuntimeError.php`
- Read: `src/Contracts/DelayScheduler.php`
- Read: `src/Contracts/EnumerableMemoryStore.php`
- Read: `src/LaravelAi/AgentNode.php`
- Read: `tests/Feature/RuntimeTest.php`
- Read: `tests/Feature/DelayInterruptTest.php`
- Read: `tests/Feature/MemoryTest.php`
- Read: `tests/Feature/LaravelAiIntegrationTest.php`
- Read: `tests/Feature/RuntimeInspectionTest.php`

- [ ] **Step 1: Inspect the dirty working tree**

Run:

```bash
git status --short
git diff --stat
```

Expected: candidate changes exist for resume context, delay scheduler, enumerable memory, text delta callbacks, and structured runtime errors.

- [ ] **Step 2: Run focused candidate tests**

Run:

```bash
vendor/bin/pest tests/Feature/RuntimeTest.php tests/Feature/DelayInterruptTest.php tests/Feature/MemoryTest.php tests/Feature/LaravelAiIntegrationTest.php tests/Feature/RuntimeInspectionTest.php
```

Expected: either PASS, or failures that directly identify gaps in the candidate SDK features.

- [ ] **Step 3: Fix only failures related to these SDK features**

If tests fail, keep edits scoped to the feature files listed in this task. Do not refactor unrelated runtime behavior.

- [ ] **Step 4: Re-run focused candidate tests**

Run:

```bash
vendor/bin/pest tests/Feature/RuntimeTest.php tests/Feature/DelayInterruptTest.php tests/Feature/MemoryTest.php tests/Feature/LaravelAiIntegrationTest.php tests/Feature/RuntimeInspectionTest.php
```

Expected: PASS.

---

### Task 2: Resume Context API

**Files:**
- Modify: `src/Runtime/NodeContext.php`
- Modify: `src/Runtime/GraphRuntime.php`
- Test: `tests/Feature/RuntimeTest.php`
- Docs: `docs/api-reference.md`
- Docs: `README.md`

- [ ] **Step 1: Confirm public methods**

`NodeContext` must expose:

```php
public function hasResumePayload(): bool;
public function resumePayload(): array;
public function interruptId(): ?string;
```

- [ ] **Step 2: Confirm resume propagation**

`GraphRuntime::resume()` and `resumeWithStateEdit()` must pass the original resume payload and resolved interrupt ID into the next resumed node context.

- [ ] **Step 3: Confirm resume context is one-superstep only**

After the resumed frontier has executed, following nodes should not see the original resume payload unless it was written into state.

- [ ] **Step 4: Add or keep feature coverage**

`tests/Feature/RuntimeTest.php` must prove normal execution has no resume payload and resumed execution exposes both payload and interrupt ID.

- [ ] **Step 5: Document the API**

Add concise `NodeContext` docs and one Human-in-the-loop example to `docs/api-reference.md` and `README.md`.

---

### Task 3: Structured Runtime Errors

**Files:**
- Modify: `src/Runtime/RuntimeError.php`
- Modify: `src/Runtime/GraphRuntime.php`
- Modify: `src/Runtime/RunInspector.php`
- Modify: `src/LaravelAi/GraphTool.php`
- Test: `tests/Feature/RuntimeInspectionTest.php`
- Docs: `docs/api-reference.md`
- Docs: `README.md`

- [ ] **Step 1: Lock target error shape**

Stable error payload:

```php
[
    'message' => '...',
    'exception_class' => RuntimeException::class,
    'code' => 123,
    'previous' => null,
    'details' => [],
    'meta' => [],
]
```

Optional `details` and `meta` may be omitted when empty. `message` must always be present.

- [ ] **Step 2: Audit all failure paths**

Search:

```bash
rg "\['message' =>|error' =>|node.failed|run.failed" src tests
```

Expected: persisted run errors, graph-tool errors, timeline errors, and traces use the normalized shape where they represent runtime failures.

- [ ] **Step 3: Keep backward readability**

`RunResult::error()` and `RunTimelineStep::error()` must still return arrays with a `message` key so existing UI code can degrade gracefully.

- [ ] **Step 4: Add nested exception coverage**

Feature tests must cover an exception with a previous exception and assert `message`, `exception_class`, `code`, and `previous`.

- [ ] **Step 5: Document structured errors**

Add the shape to `docs/api-reference.md` under Errors and Compatibility.

---

### Task 4: Delay Scheduler Contract

**Files:**
- Modify: `src/Contracts/DelayScheduler.php`
- Modify: `src/Support/QueueDelayScheduler.php`
- Modify: `src/AgentGraphServiceProvider.php`
- Modify: `src/Runtime/GraphRuntime.php`
- Test: `tests/Feature/DelayInterruptTest.php`
- Docs: `docs/api-reference.md`
- Docs: `docs/guides/production.md`

- [ ] **Step 1: Confirm contract signature**

```php
public function schedule(string $runId, array $payload, DateTimeInterface $resumeAt): void;
```

- [ ] **Step 2: Confirm default binding**

`AgentGraphServiceProvider` must bind `DelayScheduler::class` to `QueueDelayScheduler::class`.

- [ ] **Step 3: Confirm runtime usage**

Delay interrupts in `GraphRuntime` must call the contract instead of dispatching `ContinueDelayedGraphJob` directly.

- [ ] **Step 4: Confirm override test**

Feature tests must bind a recording scheduler and assert that a delay interrupt delegates scheduling with `run_id`, `interrupt_id`, and `resume_at`.

- [ ] **Step 5: Document app override**

Show how an app can bind a custom scheduler in a service provider.

---

### Task 5: Enumerable Memory Store

**Files:**
- Modify: `src/Contracts/EnumerableMemoryStore.php`
- Modify: `src/Persistence/DatabaseMemoryStore.php`
- Modify: `src/Persistence/InMemoryMemoryStore.php`
- Modify: `src/AgentGraphServiceProvider.php`
- Test: `tests/Feature/MemoryTest.php`
- Docs: `docs/api-reference.md`
- Docs: `docs/concepts/memory.md`

- [ ] **Step 1: Decide first stable signature**

Use this minimal signature unless pagination is required before release:

```php
public function listNamespace(array $scopes, string $namespace): array;
```

- [ ] **Step 2: Confirm database and in-memory parity**

Both implementations must respect scope order, namespace filtering, and expiry filtering.

- [ ] **Step 3: Confirm usage metadata behavior**

Listing should not increment `usage_count` or update `last_used_at`; reads and searches still should.

- [ ] **Step 4: Confirm container aliases**

Apps should be able to resolve `EnumerableMemoryStore::class` when the configured store supports it.

- [ ] **Step 5: Document inspector use**

Document that this is a read/list API for UI and debugging, not semantic retrieval.

---

### Task 6: AgentNode Text Delta Callback

**Files:**
- Modify: `src/LaravelAi/AgentNode.php`
- Test: `tests/Feature/LaravelAiIntegrationTest.php`
- Docs: `docs/api-reference.md`
- Docs: `docs/guides/laravel-ai-agents.md`

- [ ] **Step 1: Confirm fluent API**

```php
AgentNode::make('answer')
    ->stream()
    ->onTextDelta(function (string $delta, array $payload, NodeContext $context): void {
        // Forward to app transport.
    });
```

- [ ] **Step 2: Keep existing events**

The callback must not replace `GraphStreamDelta`, `RunEvent`, or trace recording.

- [ ] **Step 3: Confirm flexible callback arity**

Callbacks should work with one to four arguments: delta, payload, context, raw Laravel AI `TextDelta`.

- [ ] **Step 4: Document callback arguments**

Docs must state that the callback is synchronous and should stay lightweight.

---

### Task 7: Timeline API Stabilization

**Files:**
- Modify: `src/Runtime/RunTimeline.php`
- Modify: `src/Runtime/RunTimelineStep.php`
- Modify: `src/Runtime/RunInspector.php`
- Test: `tests/Feature/RuntimeInspectionTest.php`
- Docs: `docs/api-reference.md`
- Docs: `README.md`

- [ ] **Step 1: Confirm stable DTO methods**

`RunTimeline` and `RunTimelineStep` should keep existing accessor methods stable.

- [ ] **Step 2: Confirm structured timeline errors**

Timeline failed steps must expose the same normalized error shape used by `RunResult`.

- [ ] **Step 3: Confirm superstep behavior**

Timeline steps must include `completed_nodes` for parallel supersteps and retain `node_id` as the first completed node for backward readability.

- [ ] **Step 4: Confirm redaction**

State, diff, writes, interrupt, error, and metadata payloads must continue using trace redaction rules.

- [ ] **Step 5: Document timeline as inspector API**

Docs should explicitly say `timeline()` is the stable read model for debuggers and admin UIs.

---

### Task 8: Task Inspection API

**Files:**
- Modify: `src/Contracts/TaskStore.php`
- Modify: `src/Persistence/DatabaseTaskStore.php`
- Modify: `src/Persistence/InMemoryTaskStore.php`
- Modify: `src/Runtime/GraphRuntime.php`
- Modify: `src/AgentGraphManager.php`
- Test: `tests/Feature/TaskInspectionTest.php`
- Docs: `docs/api-reference.md`
- Docs: `docs/concepts/idempotent-tasks.md`

- [ ] **Step 1: Write failing task listing tests**

Cover filters for `run_id`, `node_id`, `checkpoint_id`, and `status`.

- [ ] **Step 2: Add read method to store contract**

Candidate:

```php
public function list(array $filters = [], int $limit = 50): array;
```

- [ ] **Step 3: Implement database listing**

Use indexed columns that already exist in the task migration. Order newest first or document another stable order.

- [ ] **Step 4: Implement in-memory listing**

Match database filter behavior and ordering.

- [ ] **Step 5: Expose manager/runtime API**

Candidate:

```php
AgentGraph::tasks(['run_id' => $runId], limit: 50);
```

- [ ] **Step 6: Document read-only scope**

Make clear this is inspection only. Task mutation still goes through `TaskRunner::once()`.

---

### Task 9: Node Metadata Standard

**Files:**
- Modify: `src/Runtime/NodeResult.php`
- Modify: `src/Runtime/RunInspector.php`
- Test: `tests/Feature/RuntimeInspectionTest.php`
- Docs: `docs/api-reference.md`
- Docs: `docs/concepts/state-graphs.md`

- [ ] **Step 1: Keep `withNodeMeta()` as the write API**

Do not introduce a parallel metadata mechanism unless a real limitation appears.

- [ ] **Step 2: Document stable `meta.node` keys**

Standard keys:

```php
[
    'id' => 'node-id',
    'label' => 'Human label',
    'type' => 'agent|tool|router|human|subgraph|custom',
    'status' => 'completed|interrupted|delayed|failed|skipped',
    'category' => 'optional-group',
    'source' => 'optional-source',
    'description' => 'optional-description',
]
```

- [ ] **Step 3: Confirm timeline preserves node meta**

Timeline step `meta()` and `toArray()` must expose the node metadata without forcing apps to inspect raw writes.

- [ ] **Step 4: Add a docs example**

Show a node returning `NodeResult::write(...)->withNodeMeta([...])`.

---

### Task 10: GraphTool Extension Hooks

**Files:**
- Modify: `src/LaravelAi/GraphTool.php`
- Test: `tests/Feature/LaravelAiIntegrationTest.php`
- Docs: `docs/api-reference.md`
- Docs: `docs/guides/graphs-as-tools.md`

- [ ] **Step 1: Add input mapping hook**

Candidate:

```php
public function input(Closure $mapper): self;
```

The mapper receives the Laravel AI tool request and returns graph input.

- [ ] **Step 2: Add output mapping hook**

Candidate:

```php
public function output(Closure $mapper): self;
```

The mapper receives `RunResult` and the original request, then returns the JSON-serializable tool response.

- [ ] **Step 3: Add run metadata hook**

Candidate:

```php
public function meta(Closure|array $meta): self;
```

The metadata should be used when starting new runs and ignored for plain resume unless explicitly documented.

- [ ] **Step 4: Keep failure response stable**

Failures should still return `status: failed`, `state: []`, `interrupt: null`, and normalized `error`.

- [ ] **Step 5: Document hook boundaries**

State that durable lifecycle observation belongs in `RunEvent` callbacks, not hidden persistence logic in `GraphTool`.

---

### Task 11: Child Run and Subgraph Metadata

**Files:**
- Modify: `src/Runtime/GraphRuntime.php`
- Modify: `src/Runtime/RunSnapshot.php`
- Modify: `src/Runtime/RunTimeline.php`
- Test: future subgraph or child-run tests
- Docs: `ROADMAP.md`
- Docs: `docs/api-reference.md`

- [ ] **Step 1: Do not implement full subgraphs in this pass**

Keep this task as metadata design unless a child-run feature is explicitly started.

- [ ] **Step 2: Reserve standard metadata keys**

Candidate run meta:

```php
[
    'parent' => [
        'run_id' => 'run_...',
        'checkpoint_id' => 'chk_...',
        'node_id' => 'node_id',
        'depth' => 1,
        'relationship' => 'tool|subgraph|replay|fork',
    ],
]
```

- [ ] **Step 3: Align with existing time-travel metadata**

Do not break existing `time_travel.source_run_id` and `time_travel.source_checkpoint_id`.

- [ ] **Step 4: Document as post-v1 unless used by a shipped feature**

Keep this in `ROADMAP.md` if no runtime behavior depends on it yet.

---

### Task 12: Documentation and Release Sweep

**Files:**
- Modify: `README.md`
- Modify: `ROADMAP.md`
- Modify: `CHANGELOG.md`
- Modify: `UPGRADE.md`
- Modify: `docs/api-reference.md`
- Modify: `docs/sdk-core-extension-tracker.md`

- [ ] **Step 1: Update tracker statuses**

Mark finished items as complete or move deferred items into a clear post-v1 section.

- [ ] **Step 2: Update public docs**

Every stable API in this plan must appear in `docs/api-reference.md`.

- [ ] **Step 3: Update release notes**

Mention public additions in `CHANGELOG.md`. Add `UPGRADE.md` notes if any existing error payloads or GraphTool behavior changed.

- [ ] **Step 4: Run full verification**

Run:

```bash
composer test
composer test:types
composer test:lint
composer check
```

Expected: PASS.

- [ ] **Step 5: Final review**

Run:

```bash
git diff --stat
git diff --check
```

Expected: no whitespace errors and no unrelated changes mixed into the SDK feature work.
