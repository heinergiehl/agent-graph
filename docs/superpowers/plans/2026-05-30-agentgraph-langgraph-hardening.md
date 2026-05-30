# AgentGraph LangGraph Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden AgentGraph into a high-quality, public, LangGraph-inspired PHP and Laravel SDK for durable agent workflows.

**Architecture:** Keep the package Laravel-native: `StateGraph` remains the fluent builder, `GraphRuntime` remains the durable coordinator, and store/queue/lock behavior stays behind contracts. Translate LangGraph guarantees into PHP/Laravel semantics instead of porting Python internals line by line: superstep checkpoints, task-level pending writes, explicit reducers, resumable interrupts, thread/checkpoint history, and deterministic dynamic sends.

**Tech Stack:** PHP 8.3, Laravel 12/13 components, Laravel Queue, Laravel Cache atomic locks, database and in-memory stores, Pest, Orchestra Testbench, PHPStan, Pint.

---

## Source Review

The audit file was copied for analysis to:

```text
C:\Users\Heiner\AppData\Local\Temp\agentgraph-sdk-analysis\agentgraph_sdk_audit_codex_fixplan.md
```

LangGraph was inspected from official docs and source:

- Docs: <https://docs.langchain.com/oss/python/langgraph/graph-api>
- Docs: <https://docs.langchain.com/oss/python/langgraph/interrupts>
- Docs: <https://docs.langchain.com/oss/javascript/langgraph/persistence>
- Source: <https://github.com/langchain-ai/langgraph>
- Local analysis clone: `C:\Users\Heiner\AppData\Local\Temp\agentgraph-sdk-analysis\langgraph`
- Inspected commit: `1fcb768`

The audit is directionally correct. The most important findings are real in this checkout:

- `CacheLockProvider` still falls through without a lock provider.
- `resume()`, `resumeWithStateEdit()`, and `cancel()` read and mutate run state before taking the run lock.
- `InterruptStore::resolve()` is not pending-only and not run-scoped.
- `concurrency(limit > 1)` is accepted but only `limit === 1` is enforced.
- Multiple `START` edges are accepted but `entryNode()` only returns the first one.
- Unknown reducer strings silently become `lastWriteWins`.
- Queue jobs have no package-level tries, timeout, backoff, or tags.
- Database invariants are incomplete for checkpoints, node executions, and pending interrupts.
- `GraphTool` and `DurableGraphTool` derive provider-facing tool names directly from graph keys.

Some audit items reference `filament-agentic-chatbot`, which is not part of this checkout. This plan covers core AgentGraph changes first and adds extension points that the Filament plugin can consume in a second repo-specific branch.

## LangGraph Lessons To Translate

LangGraph concepts that should guide AgentGraph:

- A checkpoint belongs to a thread and represents state at a superstep boundary.
- Pending writes are stored per task so successful sibling nodes do not need to rerun after a sibling fails.
- `START` can feed multiple entry nodes, and fan-out nodes in one frontier read the same base state.
- Reducers are explicit channel-level merge policies; concurrent writes without reducers should fail clearly.
- `Send` is dynamic fan-out with target node plus local input.
- `Command(resume=...)` can resume one or more interrupts, and interrupts rely on persistence.
- Interrupt resume re-enters the paused node with resume values scoped to the task.
- Runtime context is distinct from graph state and should carry immutable dependencies or per-run options.

AgentGraph should not copy LangGraph's Python task runner. In PHP/Laravel, the equivalent durable boundaries are run locks, database transactions, queue jobs, store-level idempotency, and explicit application boot-time graph registration.

## Working Rules

- Implement one feature task at a time.
- For every feature: write failing tests first, implement the smallest change, run focused tests, then run the broader package checks before the feature is considered done.
- Prefer additive APIs and config. If behavior changes are intentionally breaking, document them in `UPGRADE.md`.
- Do not introduce plugin-specific runtime behavior into core. Expose deterministic hooks and options instead.

## File Map

Primary runtime files:

- `src/Runtime/GraphRuntime.php`: run/resume/cancel locking, continuation boundaries, target validation, runtime options.
- `src/Runtime/RuntimeScheduler.php`: schedule normalization and validation.
- `src/Runtime/NodeResult.php`: dynamic goto/send surface.
- `src/Runtime/Send.php`: dynamic send DTO validation.
- `src/Graph/StateGraph.php`: fluent API validation.
- `src/Graph/GraphDefinition.php`: graph validation and entry nodes.
- `src/Graph/ConcurrencyPolicy.php`: exclusive-only semantics until semaphores exist.
- `src/State/StateReducer.php`: strict reducer normalization.
- `src/Support/CacheLockProvider.php`: fail-closed lock behavior.
- `src/Support/AgentGraphQueue.php`: queue connection and queue configuration helper.
- `src/Support/QueueDelayScheduler.php`: delayed continuation scheduling.

Persistence files:

- `src/Contracts/InterruptStore.php`
- `src/Persistence/DatabaseInterruptStore.php`
- `src/Persistence/InMemoryInterruptStore.php`
- `src/Persistence/DatabaseCheckpointStore.php`
- `src/Persistence/DatabaseNodeExecutionStore.php`
- `src/Persistence/InMemoryNodeExecutionStore.php`
- `database/migrations/*.php`

Queue files:

- `src/Queue/RunGraphJob.php`
- `src/Queue/ResumeGraphJob.php`
- `src/Queue/ContinueDelayedGraphJob.php`
- `src/Queue/NodeExecutionJob.php`
- `src/Queue/ContinueSuperstepJob.php`

Laravel AI adapter files:

- `src/LaravelAi/GraphTool.php`
- `src/LaravelAi/DurableGraphTool.php`

Docs and DX files:

- `config/agent-graph.php`
- `src/Console/DoctorCommand.php`
- `README.md`
- `UPGRADE.md`
- `docs/api-reference.md`
- `docs/guides/production.md`
- `docs/concepts/state-graphs.md`
- `docs/concepts/interrupts.md`
- `docs/concepts/checkpoints.md`
- `docs/reference-sources.md`

## Task 0: Baseline Branch And Evidence

**Files:**
- No source edits required.

- [x] **Step 1: Create branch**

Run:

```bash
git switch -c codex/sdk-langgraph-hardening-plan
```

Expected: current branch is `codex/sdk-langgraph-hardening-plan`.

- [x] **Step 2: Save audit copy outside the repo**

Run:

```powershell
$tmp = Join-Path $env:TEMP 'agentgraph-sdk-analysis'
New-Item -ItemType Directory -Force -Path $tmp | Out-Null
Copy-Item -LiteralPath 'C:/Users/Heiner/Desktop/dls/agentgraph_sdk_audit_codex_fixplan.md' -Destination (Join-Path $tmp 'agentgraph_sdk_audit_codex_fixplan.md') -Force
```

Expected: copied markdown exists under `%TEMP%\agentgraph-sdk-analysis`.

- [x] **Step 3: Run focused baseline test**

Run:

```bash
composer test -- --filter=RuntimeHardeningTest
```

Observed baseline: 10 tests passed, 34 assertions.

## Task 1: Fail-Closed Atomic Lock Provider

**Files:**
- Modify: `config/agent-graph.php`
- Modify: `src/Support/CacheLockProvider.php`
- Modify: `src/Console/DoctorCommand.php`
- Modify: `README.md`
- Modify: `docs/guides/production.md`
- Modify: `docs/api-reference.md`
- Test: `tests/Feature/LockProviderConfigurationTest.php`
- Test: `tests/Feature/ConsoleCommandsTest.php`

- [x] **Step 1: Write failing lock-provider tests**

Create `tests/Feature/LockProviderConfigurationTest.php` with these cases:

```php
<?php

use Heiner\AgentGraph\Support\CacheLockProvider;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;

it('throws when cache store has no atomic lock support and fail closed is enabled', function () {
    config()->set('agent-graph.locks.fail_without_provider', true);
    app()->instance('cache', new Repository(new class implements Store {
        public function get($key) {}
        public function many(array $keys) { return []; }
        public function put($key, $value, $seconds) { return true; }
        public function putMany(array $values, $seconds) { return true; }
        public function increment($key, $value = 1) {}
        public function decrement($key, $value = 1) {}
        public function forever($key, $value) { return true; }
        public function forget($key) { return true; }
        public function flush() { return true; }
        public function getPrefix() { return ''; }
    }));

    expect(fn () => (new CacheLockProvider)->withLock('agent-graph:test', fn () => 'ran'))
        ->toThrow(RuntimeException::class, 'Cache store does not support atomic locks');
});

it('allows explicit fail open mode for local tests', function () {
    config()->set('agent-graph.locks.fail_without_provider', false);
    app()->instance('cache', new Repository(new class implements Store {
        public function get($key) {}
        public function many(array $keys) { return []; }
        public function put($key, $value, $seconds) { return true; }
        public function putMany(array $values, $seconds) { return true; }
        public function increment($key, $value = 1) {}
        public function decrement($key, $value = 1) {}
        public function forever($key, $value) { return true; }
        public function forget($key) { return true; }
        public function flush() { return true; }
        public function getPrefix() { return ''; }
    }));

    expect((new CacheLockProvider)->withLock('agent-graph:test', fn () => 'ran'))->toBe('ran');
});
```

Run:

```bash
composer test -- --filter=LockProviderConfigurationTest
```

Expected before implementation: first test fails because `CacheLockProvider` silently runs the callback.

- [x] **Step 2: Add lock config**

Change `config/agent-graph.php`:

```php
'locks' => [
    'ttl_seconds' => env('AGENT_GRAPH_LOCK_TTL_SECONDS', 300),
    'block_seconds' => env('AGENT_GRAPH_LOCK_BLOCK_SECONDS', 5),
    'fail_without_provider' => env('AGENT_GRAPH_LOCK_FAIL_WITHOUT_PROVIDER', true),
],
```

- [x] **Step 3: Fail closed in `CacheLockProvider`**

Change `src/Support/CacheLockProvider.php` so the fallback is explicit:

```php
if (! method_exists($store, 'lock')) {
    if ((bool) config('agent-graph.locks.fail_without_provider', true)) {
        throw new RuntimeException(
            'Cache store does not support atomic locks. Configure a lock-capable cache store or set agent-graph.locks.fail_without_provider=false for local throwaway runs.'
        );
    }

    return $callback();
}
```

Add `use RuntimeException;`.

- [x] **Step 4: Expand doctor lock output**

Change `src/Console/DoctorCommand.php` to print `PASS`, `WARN`, or `FAIL`:

```php
$supportsLocks = $this->supportsCacheLocks();
$failClosed = (bool) config('agent-graph.locks.fail_without_provider', true);

if (! $supportsLocks && $failClosed) {
    $failed = true;
    $this->error('FAIL cache locks: unavailable while fail_without_provider=true');
} elseif (! $supportsLocks) {
    $this->warn('WARN cache locks: unavailable and fail_without_provider=false');
} else {
    $this->info('PASS cache locks: available');
}
```

- [x] **Step 5: Document production requirement**

Update `README.md`, `docs/guides/production.md`, and `docs/api-reference.md` with:

```text
Production runs require a cache store that supports atomic locks. Keep AGENT_GRAPH_LOCK_FAIL_WITHOUT_PROVIDER=true outside local throwaway tests.
```

- [x] **Step 6: Verify**

Run:

```bash
composer test -- --filter=LockProviderConfigurationTest
composer test -- --filter=ConsoleCommandsTest
```

Expected: both focused suites pass.

## Task 2: Atomic Resume, State Edit Resume, And Cancel

**Files:**
- Modify: `src/Runtime/GraphRuntime.php`
- Modify: `src/Contracts/InterruptStore.php`
- Modify: `src/Persistence/DatabaseInterruptStore.php`
- Modify: `src/Persistence/InMemoryInterruptStore.php`
- Test: `tests/Feature/RuntimeAtomicControlsTest.php`
- Test: `tests/Integration/DatabaseStoresTest.php`
- Test: `tests/Feature/RuntimeHardeningTest.php`

- [x] **Step 1: Write failing tests for pending-only interrupt resolution**

Add to `tests/Integration/DatabaseStoresTest.php`:

```php
it('resolves pending interrupts only once for the expected run', function () {
    $runs = app('agent-graph.runs');
    $interrupts = app('agent-graph.interrupts');

    $run = $runs->create('resolve-pending', '1', 'thread-resolve', []);
    $interrupt = $interrupts->create([
        'run_id' => $run['public_id'],
        'checkpoint_id' => 'chk_1',
        'node_id' => 'ask',
        'type' => 'input',
        'payload' => ['prompt' => 'Answer'],
    ]);

    $resolved = $interrupts->resolvePending($interrupt['interrupt_id'], $run['public_id'], ['answer' => 'one']);

    expect($resolved['status'])->toBe('resolved')
        ->and($resolved['response'])->toBe(['answer' => 'one']);

    expect(fn () => $interrupts->resolvePending($interrupt['interrupt_id'], $run['public_id'], ['answer' => 'two']))
        ->toThrow(RuntimeException::class, 'Interrupt is no longer pending');
});
```

Expected before implementation: test fails because `resolvePending()` does not exist.

- [x] **Step 2: Write failing tests that runtime controls run under the run lock**

Create `tests/Feature/RuntimeAtomicControlsTest.php`:

```php
<?php

use Heiner\AgentGraph\Contracts\LockProvider;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('resolves resume interrupts while holding the run lock', function () {
    $locks = new RuntimeAtomicRecordingLockProvider;
    app()->instance(LockProvider::class, $locks);

    AgentGraph::define(
        StateGraph::make('atomic_resume_graph')
            ->state(['answer' => 'string|null'])
            ->node('ask', RuntimeAtomicAskNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', StateGraph::END)
            ->compile(),
    );

    $run = AgentGraph::graph('atomic_resume_graph')->thread('atomic-resume')->run();
    $completed = AgentGraph::resume($run->runId(), [
        'interrupt_id' => $run->interrupt()['interrupt_id'],
        'answer' => 'done',
    ]);

    expect($completed->status())->toBe('completed')
        ->and($locks->keys)->toContain('agent-graph:run:'.$run->runId());
});

final class RuntimeAtomicAskNode
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->hasResumePayload()) {
            return NodeResult::end(['answer' => (string) $context->state('answer')]);
        }

        return NodeResult::interrupt('input', ['prompt' => 'Answer']);
    }
}

final class RuntimeAtomicRecordingLockProvider implements LockProvider
{
    public array $keys = [];

    public function withLock(string $key, Closure $callback): mixed
    {
        $this->keys[] = $key;

        return $callback();
    }
}
```

This test proves the lock is used. Add a second test after `resolvePending()` exists to assert duplicate resume fails with `Interrupt is no longer pending`.

- [x] **Step 3: Extend `InterruptStore`**

Change `src/Contracts/InterruptStore.php`:

```php
public function resolvePending(string $interruptId, string $runId, array $response, ?string $resolvedBy = null): array;
```

Keep `resolve()` for backward compatibility, but runtime code should move to `resolvePending()`.

- [x] **Step 4: Implement database pending-only update**

Change `src/Persistence/DatabaseInterruptStore.php`:

```php
public function resolvePending(string $interruptId, string $runId, array $response, ?string $resolvedBy = null): array
{
    $updated = $this->query()
        ->where('interrupt_id', $interruptId)
        ->where('run_id', $runId)
        ->where('status', 'pending')
        ->update([
            'status' => 'resolved',
            'response' => $this->encode($response),
            'resolved_by' => $resolvedBy,
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

    if ($updated < 1) {
        throw new RuntimeException("Interrupt [{$interruptId}] is no longer pending for run [{$runId}].");
    }

    return $this->find($interruptId)
        ?? throw new RuntimeException("Interrupt [{$interruptId}] was not found after resolving.");
}
```

Add `use RuntimeException;`.

- [x] **Step 5: Implement in-memory pending-only update**

Change `src/Persistence/InMemoryInterruptStore.php`:

```php
public function resolvePending(string $interruptId, string $runId, array $response, ?string $resolvedBy = null): array
{
    $interrupt = $this->interrupts[$interruptId] ?? null;

    if ($interrupt === null || $interrupt['run_id'] !== $runId || $interrupt['status'] !== 'pending') {
        throw new RuntimeException("Interrupt [{$interruptId}] is no longer pending for run [{$runId}].");
    }

    return $this->resolve($interruptId, $response, $resolvedBy);
}
```

- [x] **Step 6: Split continuation into locked and unlocked bodies**

In `src/Runtime/GraphRuntime.php`, keep `continue()` as the lock wrapper:

```php
protected function continue(GraphDefinition $graph, array $run, array $state, array $nextNodes, array $resumeContext = []): RunResult
{
    return $this->locks->withLock('agent-graph:run:'.$run['public_id'], function () use ($graph, $run, $state, $nextNodes, $resumeContext): RunResult {
        return $this->continueLocked($graph, $run, $state, $nextNodes, $resumeContext);
    });
}
```

Move the existing body of `continue()` into:

```php
protected function continueLocked(GraphDefinition $graph, array $run, array $state, array $nextNodes, array $resumeContext = []): RunResult
```

- [x] **Step 7: Re-fetch terminal status at the beginning of `continueLocked()`**

At the top of `continueLocked()`:

```php
$freshRun = $this->runs->find($run['public_id']) ?? $run;

if (RunStatus::isTerminal($freshRun['status'] ?? null)) {
    $checkpoint = $this->checkpoints->latestForRun($freshRun['public_id']);

    return new RunResult($freshRun, $checkpoint['state'] ?? $state);
}

$run = $freshRun;
```

- [x] **Step 8: Move all resume mutation inside the run lock**

Change `resume()`:

```php
public function resume(string $runId, array $payload, array $graphs, bool $strictKeys = false): RunResult
{
    return $this->locks->withLock('agent-graph:run:'.$runId, function () use ($runId, $payload, $graphs, $strictKeys): RunResult {
        $run = $this->runs->find($runId) ?? throw new RuntimeException("Run [{$runId}] was not found.");
        $this->assertRunCanResume($run);
        $graph = $graphs[$run['graph_key']] ?? throw new RuntimeException("Graph [{$run['graph_key']}] is not defined.");
        $this->assertGraphVersionMatches($run, $graph, 'Run');
        $checkpoint = $this->checkpoints->latestForRun($runId) ?? throw new RuntimeException("Run [{$runId}] has no checkpoint.");
        $interrupt = $this->interrupts->pendingForRun($runId);

        $resumePayload = $payload;
        unset($resumePayload['interrupt_id']);
        $this->assertStatePatchMatchesSchema($graph, $resumePayload, strictKeys: $strictKeys);

        $resumeInterruptId = null;
        if (isset($payload['interrupt_id'])) {
            $resumeInterruptId = (string) $payload['interrupt_id'];
            $this->assertMatchingPendingInterrupt($runId, $resumeInterruptId, $interrupt);
            $this->interrupts->resolvePending($resumeInterruptId, $runId, $payload);
        } elseif ($interrupt !== null && in_array($run['status'], ['interrupted', 'delayed'], true)) {
            throw new InvalidArgumentException("Run [{$runId}] requires interrupt_id to resume.");
        }

        $state = array_merge($checkpoint['state'], $resumePayload);
        $next = $checkpoint['next_nodes'] ?: $graph->entryNodes();
        $run = $this->runs->update($runId, ['status' => 'running']);
        $this->dispatchRunEvent('run.resumed', new GraphResumed($runId, $run['thread_id'], $graph->key(), payload: $resumePayload));

        return $this->continueLocked($graph, $run, $state, $next, [
            'resume_payload' => $resumePayload,
            'interrupt_id' => $resumeInterruptId,
        ]);
    });
}
```

This snippet assumes Task 5 adds `entryNodes()`. Until Task 5 lands, use `[$graph->entryNode()]`.

- [x] **Step 9: Apply the same pattern to `resumeWithStateEdit()` and `cancel()`**

`resumeWithStateEdit()` must call `resolvePending($interruptId, $runId, ...)` inside the same run lock and continue via `continueLocked()`.

`cancel()` must:

```php
return $this->locks->withLock('agent-graph:run:'.$runId, function () use ($runId, $meta): RunResult {
    $run = $this->runs->find($runId) ?? throw new RuntimeException("Run [{$runId}] was not found.");

    if (! RunStatus::isActive($run['status'] ?? null)) {
        throw new RuntimeException("Run [{$runId}] is {$run['status']} and cannot be cancelled.");
    }

    $run = $this->runs->update($runId, [
        'status' => 'cancelled',
        'cancelled_at' => now(),
        'meta' => array_merge($run['meta'] ?? [], ['cancelled' => $meta]),
    ]);

    $checkpoint = $this->checkpoints->latestForRun($runId);
    $this->dispatchRunEvent('run.cancelled', new GraphRunCancelled($runId, $run['thread_id'], $run['graph_key'], payload: $meta));

    return new RunResult($run, $checkpoint['state'] ?? []);
});
```

- [x] **Step 10: Verify**

Run:

```bash
composer test -- --filter=RuntimeAtomicControlsTest
composer test -- --filter=RuntimeHardeningTest
composer test -- --filter=DatabaseStoresTest
```

Expected: all focused tests pass, and there is no nested run-lock deadlock.

Observed: `RuntimeAtomicControlsTest`, `RuntimeHardeningTest`, `StateEditResumeTest`, `CancellationTest`, `DatabaseStoresTest`, `ConsoleCommandsTest`, and `LockProviderConfigurationTest` all pass after implementation.

Final verification: `composer test` passes with 176 passed, 1 skipped optional pgvector integration test; `composer test:lint` passes; `composer test:types` passes.

## Task 3: Strict API Contracts For Reducers And Concurrency

**Files:**
- Modify: `src/State/StateReducer.php`
- Modify: `src/Graph/ConcurrencyPolicy.php`
- Modify: `src/Graph/StateGraph.php`
- Modify: `README.md`
- Modify: `UPGRADE.md`
- Modify: `docs/api-reference.md`
- Test: `tests/Unit/StateGraphTest.php`
- Test: `tests/Feature/RuntimeHardeningTest.php`

- [x] **Step 1: Write failing reducer test**

Add to `tests/Unit/StateGraphTest.php`:

```php
it('rejects unknown reducer strings', function () {
    $reducer = new StateReducer(['items' => 'apend']);

    expect(fn () => $reducer->apply(['items' => []], ['items' => ['x']]))
        ->toThrow(InvalidArgumentException::class, 'Unknown reducer [apend]');
});
```

- [x] **Step 2: Make reducer strings strict**

Change `src/State/StateReducer.php`:

```php
return match ($reducer) {
    'append' => Reducer::append(),
    'merge' => Reducer::merge(),
    'messages', 'add_messages' => Reducer::addMessages(),
    'max', 'max_confidence' => Reducer::maxConfidence(),
    default => throw new InvalidArgumentException("Unknown reducer [{$reducer}]."),
};
```

Add `use InvalidArgumentException;`.

- [x] **Step 3: Write failing concurrency test**

Add to `tests/Unit/StateGraphTest.php`:

```php
it('rejects semaphore concurrency limits until they are implemented', function () {
    StateGraph::make('invalid_concurrency')
        ->node('call_api', NoopNode::class)
        ->edge(StateGraph::START, 'call_api')
        ->concurrency('call_api', limit: 2)
        ->compile();
})->throws(InvalidArgumentException::class, 'only exclusive node concurrency with limit=1');
```

- [x] **Step 4: Enforce exclusive-only concurrency**

In `src/Graph/ConcurrencyPolicy.php` constructor:

```php
if ($limit !== 1) {
    throw new InvalidArgumentException('AgentGraph currently supports only exclusive node concurrency with limit=1. Semaphore limits greater than 1 are not implemented.');
}
```

Add `use InvalidArgumentException;`.

- [x] **Step 5: Document behavior changes**

Add to `UPGRADE.md`:

```text
Unknown reducer strings now throw instead of silently falling back to last-write-wins. Fix typos such as `apend` to `append`.
`StateGraph::concurrency()` currently supports exclusive locks only. Calls with `limit > 1` now throw because semaphore concurrency is not implemented.
```

- [x] **Step 6: Verify**

Run:

```bash
composer test -- --filter=StateGraphTest
composer test -- --filter=RuntimeHardeningTest
```

Expected: strict reducer and concurrency tests pass.

Observed: `StateGraphTest` and `RuntimeHardeningTest` pass after implementation.

Final verification: `composer test` passes with 178 passed, 1 skipped optional pgvector integration test; `composer test:lint` passes; `composer test:types` passes.

## Task 4: Multiple START Edges And Entry Node Semantics

**Files:**
- Modify: `src/Graph/GraphDefinition.php`
- Modify: `src/Runtime/GraphRuntime.php`
- Modify: `docs/api-reference.md`
- Modify: `docs/concepts/state-graphs.md`
- Test: `tests/Unit/StateGraphTest.php`
- Test: `tests/Feature/SuperstepTest.php`

- [x] **Step 1: Write failing multiple entry test**

Add to `tests/Feature/SuperstepTest.php`:

```php
it('runs multiple start edges in the first superstep', function () {
    AgentGraph::define(
        StateGraph::make('superstep_multiple_start_edges')
            ->state([
                'input' => 'string',
                'seen' => 'array',
            ])
            ->reducer('seen', 'append')
            ->node('left_start', MultipleStartLeftNode::class)
            ->node('right_start', MultipleStartRightNode::class)
            ->edge(StateGraph::START, 'left_start')
            ->edge(StateGraph::START, 'right_start')
            ->compile(),
    );

    $run = AgentGraph::graph('superstep_multiple_start_edges')
        ->thread('multiple-start')
        ->input(['input' => 'root', 'seen' => []])
        ->run();

    expect($run->completed())->toBeTrue()
        ->and($run->state('seen'))->toBe(['left:root', 'right:root']);
});
```

Node classes:

```php
final class MultipleStartLeftNode
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['seen' => ['left:'.$context->state('input')]]);
    }
}

final class MultipleStartRightNode
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end(['seen' => ['right:'.$context->state('input')]]);
    }
}
```

Expected before implementation: only the first entry node runs.

- [x] **Step 2: Add `entryNodes()`**

In `src/Graph/GraphDefinition.php`:

```php
public function entryNodes(): array
{
    return $this->edges[StateGraph::START] ?? throw new InvalidArgumentException('Graph has no entry node.');
}
```

Keep `entryNode()` for compatibility:

```php
public function entryNode(): string
{
    return $this->entryNodes()[0];
}
```

- [x] **Step 3: Use all entry nodes in runtime**

Change:

```php
return $this->continue($graph, $run, $input, [$graph->entryNode()]);
```

to:

```php
return $this->continue($graph, $run, $input, $graph->entryNodes());
```

Change resume fallbacks from `[$graph->entryNode()]` to `$graph->entryNodes()`.

Change `successorsOf(StateGraph::START, ...)` to return `$this->entryNodes()`.

- [x] **Step 4: Document multiple start behavior**

Add:

```text
Multiple edges from `StateGraph::START` are valid. They schedule all entry nodes in the first superstep. Each entry node reads the same initial input state, and concurrent writes to the same channel require an explicit reducer.
```

- [x] **Step 5: Verify**

Run:

```bash
composer test -- --filter=SuperstepTest
composer test -- --filter=StateGraphTest
```

Expected: existing fan-out tests and new multiple-start test pass.

Observed: `SuperstepTest` and `StateGraphTest` pass after implementation.

Final verification: `composer test` passes with 180 passed, 1 skipped optional pgvector integration test; `composer test:lint` passes; `composer test:types` passes.

## Task 5: Validate Dynamic Goto And Send Targets Before Persistence

**Files:**
- Modify: `src/Runtime/GraphRuntime.php`
- Modify: `src/Runtime/RuntimeScheduler.php`
- Modify: `src/Runtime/Send.php`
- Modify: `src/Runtime/NodeResult.php`
- Modify: `docs/concepts/state-graphs.md`
- Test: `tests/Feature/SuperstepTest.php`
- Test: `tests/Unit/NodeResultTest.php`

- [x] **Step 1: Write failing target validation tests**

Add to `tests/Feature/SuperstepTest.php`:

```php
it('fails immediately when a node returns goto for an unknown target', function () {
    AgentGraph::define(
        StateGraph::make('invalid_goto_target')
            ->node('route', InvalidGotoNode::class)
            ->edge(StateGraph::START, 'route')
            ->compile(),
    );

    $run = AgentGraph::graph('invalid_goto_target')->thread('invalid-goto')->run();

    expect($run->status())->toBe('failed')
        ->and($run->error()['message'])->toContain('Node [route] returned unknown goto target [missing]');
});

it('fails immediately when a node sends to an unknown target', function () {
    AgentGraph::define(
        StateGraph::make('invalid_send_target')
            ->node('dispatch', InvalidSendNode::class)
            ->edge(StateGraph::START, 'dispatch')
            ->compile(),
    );

    $run = AgentGraph::graph('invalid_send_target')->thread('invalid-send')->run();

    expect($run->status())->toBe('failed')
        ->and($run->error()['message'])->toContain('Node [dispatch] returned unknown send target [missing]');
});
```

Node classes:

```php
final class InvalidGotoNode
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::goto('missing');
    }
}

final class InvalidSendNode
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::send('missing');
    }
}
```

- [x] **Step 2: Implement graph-aware schedule validation**

Add a `GraphRuntime` method:

```php
protected function assertNodeResultTargetsAreKnown(GraphDefinition $graph, string $sourceNode, NodeResult $result): void
{
    if ($result->nextNode() !== null && ! $graph->hasEndpoint($result->nextNode())) {
        throw new InvalidArgumentException("Node [{$sourceNode}] returned unknown goto target [{$result->nextNode()}].");
    }

    foreach ($result->sends() as $send) {
        if (! $graph->hasEndpoint($send->node()) || $send->node() === StateGraph::START) {
            throw new InvalidArgumentException("Node [{$sourceNode}] returned unknown send target [{$send->node()}].");
        }
    }
}
```

Call it immediately after each successful `invokeNode()` and before `recordNodeExecutionIfEnabled()` or checkpoint persistence.

- [x] **Step 3: Keep `END` semantics clear**

`RuntimeScheduler::normalize()` currently drops `Send` to `END`. Keep that for static next nodes. Reject explicit `Send::to(StateGraph::END)` in result validation because `Send` means task execution and `END` is not executable.

- [x] **Step 4: Verify**

Run:

```bash
composer test -- --filter=SuperstepTest
composer test -- --filter=NodeResultTest
```

Expected: invalid targets fail the run before a successful checkpoint is created for bad schedules.

Observed: `SuperstepTest` and `NodeResultTest` pass after implementation. Invalid dynamic targets fail the run before checkpoint persistence.

Final verification: `composer test` passes with 183 passed, 1 skipped optional pgvector integration test; `composer test:lint` passes; `composer test:types` passes.

## Task 6: Queue Job Defaults, Tags, And Database Invariants

**Files:**
- Modify: `config/agent-graph.php`
- Modify: `src/Queue/RunGraphJob.php`
- Modify: `src/Queue/ResumeGraphJob.php`
- Modify: `src/Queue/NodeExecutionJob.php`
- Modify: `src/Queue/ContinueSuperstepJob.php`
- Modify: `src/Queue/ContinueDelayedGraphJob.php`
- Modify: `src/Persistence/DatabaseCheckpointStore.php`
- Modify: `src/Persistence/DatabaseNodeExecutionStore.php`
- Modify: `src/Persistence/DatabaseInterruptStore.php`
- Add: `database/migrations/2026_05_30_000000_add_agent_graph_runtime_invariants.php`
- Modify: `docs/guides/production.md`
- Test: `tests/Feature/QueueHardeningTest.php`
- Test: `tests/Integration/PersistenceHardeningTest.php`

- [ ] **Step 1: Add queue config tests**

Add to `tests/Feature/QueueHardeningTest.php`:

```php
it('applies configured queue job defaults and tags', function () {
    config()->set('agent-graph.execution.job_tries', 5);
    config()->set('agent-graph.execution.job_timeout', 120);
    config()->set('agent-graph.execution.job_backoff', [1, 5, 10]);

    $job = new NodeExecutionJob('nex_test');

    expect($job->tries)->toBe(5)
        ->and($job->timeout)->toBe(120)
        ->and($job->backoff())->toBe([1, 5, 10])
        ->and($job->tags())->toContain('agent-graph', 'agent-graph:node-execution', 'agent-graph:execution:nex_test');
});
```

- [ ] **Step 2: Add execution config**

In `config/agent-graph.php`:

```php
'execution' => [
    'mode' => env('AGENT_GRAPH_EXECUTION_MODE', 'sync'),
    'queue_connection' => env('AGENT_GRAPH_EXECUTION_QUEUE_CONNECTION'),
    'queue' => env('AGENT_GRAPH_EXECUTION_QUEUE'),
    'node_lease_seconds' => env('AGENT_GRAPH_EXECUTION_NODE_LEASE_SECONDS', 300),
    'job_tries' => env('AGENT_GRAPH_JOB_TRIES', 3),
    'job_timeout' => env('AGENT_GRAPH_JOB_TIMEOUT', 300),
    'job_backoff' => array_map('intval', explode(',', env('AGENT_GRAPH_JOB_BACKOFF', '5'))),
],
```

- [ ] **Step 3: Add job defaults**

In each job constructor, set:

```php
public int $tries;
public int $timeout;

public function __construct(...)
{
    ...
    $this->tries = (int) config('agent-graph.execution.job_tries', 3);
    $this->timeout = (int) config('agent-graph.execution.job_timeout', 300);
}

public function backoff(): int|array
{
    return config('agent-graph.execution.job_backoff', 5);
}
```

Add `tags()` per job:

```php
public function tags(): array
{
    return ['agent-graph', 'agent-graph:node-execution', 'agent-graph:execution:'.$this->executionId];
}
```

Use equivalent tags for run, resume, delayed resume, and continue-superstep jobs.

- [ ] **Step 4: Add database invariant migration**

Create `database/migrations/2026_05_30_000000_add_agent_graph_runtime_invariants.php`:

```php
<?php

use Heiner\AgentGraph\Persistence\AgentGraphMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends AgentGraphMigration
{
    public function up(): void
    {
        Schema::table(config('agent-graph.tables.checkpoints', 'agent_graph_checkpoints'), function (Blueprint $table): void {
            $table->unique(['run_id', 'step'], 'agent_graph_checkpoints_run_step_unique');
        });

        Schema::table(config('agent-graph.tables.node_executions', 'agent_graph_node_executions'), function (Blueprint $table): void {
            $table->unique(['run_id', 'step', 'schedule_index'], 'agent_graph_node_executions_schedule_unique');
        });

        Schema::table(config('agent-graph.tables.interrupts', 'agent_graph_interrupts'), function (Blueprint $table): void {
            $table->index(['run_id', 'status'], 'agent_graph_interrupts_run_status_index');
        });
    }

    public function down(): void
    {
        Schema::table(config('agent-graph.tables.interrupts', 'agent_graph_interrupts'), function (Blueprint $table): void {
            $table->dropIndex('agent_graph_interrupts_run_status_index');
        });

        Schema::table(config('agent-graph.tables.node_executions', 'agent_graph_node_executions'), function (Blueprint $table): void {
            $table->dropUnique('agent_graph_node_executions_schedule_unique');
        });

        Schema::table(config('agent-graph.tables.checkpoints', 'agent_graph_checkpoints'), function (Blueprint $table): void {
            $table->dropUnique('agent_graph_checkpoints_run_step_unique');
        });
    }
};
```

Do not add a portable partial unique index for pending interrupts in the Laravel migration because SQLite and MySQL differ. Enforce one pending interrupt per run in `DatabaseInterruptStore::create()` inside a transaction with a `lockForUpdate()` check.

- [ ] **Step 5: Make `latestForRun()` deterministic**

Change `DatabaseCheckpointStore::latestForRun()`:

```php
$record = $this->query()
    ->where('run_id', $runId)
    ->orderByDesc('step')
    ->orderByDesc('id')
    ->first();
```

- [ ] **Step 6: Enforce pending interrupt invariant in stores**

Before insert in `DatabaseInterruptStore::create()`:

```php
$existing = $this->query()
    ->where('run_id', $interrupt['run_id'])
    ->where('status', 'pending')
    ->lockForUpdate()
    ->first();

if ($existing !== null) {
    throw new RuntimeException("Run [{$interrupt['run_id']}] already has a pending interrupt.");
}
```

Mirror the check in `InMemoryInterruptStore::create()`.

- [ ] **Step 7: Verify duplicate queue job idempotency**

Add tests that manually run the same `NodeExecutionJob` twice and the same `ContinueSuperstepJob` twice. Expected result:

```text
The node execution is completed once, the second job returns without changing the completed result, and the run has one checkpoint for the step.
```

- [ ] **Step 8: Verify**

Run:

```bash
composer test -- --filter=QueueHardeningTest
composer test -- --filter=PersistenceHardeningTest
composer test -- --filter=MigrationConfigurationTest
```

Expected: queue defaults, duplicate job guards, and migration checks pass.

## Task 7: LangGraph-Style Pending Writes Recovery

**Files:**
- Modify: `src/Runtime/GraphRuntime.php`
- Modify: `src/Persistence/DatabaseNodeExecutionStore.php`
- Modify: `src/Persistence/InMemoryNodeExecutionStore.php`
- Modify: `docs/concepts/checkpoints.md`
- Modify: `docs/guides/production.md`
- Test: `tests/Feature/QueuedSuperstepTest.php`
- Test: `tests/Feature/NodeRetryPolicyTest.php`

- [ ] **Step 1: Define AgentGraph's PHP-specific guarantee**

Document this guarantee in `docs/concepts/checkpoints.md`:

```text
In `queued_supersteps` mode, AgentGraph treats node execution rows as task-level pending writes. If one node in a frontier succeeds and another fails or is retried, the completed node execution is not rerun. The continuation job aggregates durable node execution results into one checkpoint once the frontier is complete.
```

For sync mode, document:

```text
Sync mode persists only completed superstep checkpoints. A PHP process failure inside a sync superstep can require rerunning the current frontier. Use queued supersteps for task-level recovery across workers.
```

- [ ] **Step 2: Add regression test for completed sibling not rerun**

Add to `tests/Feature/QueuedSuperstepTest.php` a graph with two queued nodes:

```php
it('does not rerun completed sibling node executions when a queued superstep is retried', function () {
    config()->set('agent-graph.execution.mode', 'queued_supersteps');
    Queue::fake();

    QueuedPendingWritesCounter::$calls = 0;

    AgentGraph::define(
        StateGraph::make('queued_pending_writes_recovery')
            ->state(['items' => 'array'])
            ->reducer('items', 'append')
            ->node('split', QueuedPendingWritesSplitNode::class)
            ->node('stable', QueuedPendingWritesStableNode::class)
            ->node('flaky', QueuedPendingWritesFlakyNode::class)
            ->edge(StateGraph::START, 'split')
            ->edge('split', 'stable')
            ->edge('split', 'flaky')
            ->compile(),
    );

    $run = AgentGraph::graph('queued_pending_writes_recovery')->thread('queued-pending')->run();
    $executions = AgentGraph::nodeExecutions($run->runId());

    app(Heiner\AgentGraph\AgentGraphManager::class)->executeQueuedNode($executions[0]['execution_id']);
    app(Heiner\AgentGraph\AgentGraphManager::class)->executeQueuedNode($executions[1]['execution_id']);
    app(Heiner\AgentGraph\AgentGraphManager::class)->executeQueuedNode($executions[0]['execution_id']);

    expect(QueuedPendingWritesCounter::$calls)->toBe(1);
});
```

Use the existing node execution claim behavior to satisfy the test. If the test already passes, keep it as coverage.

- [ ] **Step 3: Ensure completed executions are immutable**

In `DatabaseNodeExecutionStore::claim()`, completed, interrupted, and failed executions already return their record. Add tests for this exact contract and keep it stable.

- [ ] **Step 4: Add checkpoint aggregation guard**

In `GraphRuntime::continueQueuedSuperstep()`, before creating a checkpoint, recheck:

```php
if ($this->checkpoints->latestForRun($runId) !== null && (int) $latestCheckpoint['step'] >= $step) {
    return new RunResult($run, $latestCheckpoint['state']);
}
```

This exists today. Keep it and add regression coverage for duplicate continue jobs.

- [ ] **Step 5: Verify**

Run:

```bash
composer test -- --filter=QueuedSuperstepTest
composer test -- --filter=NodeRetryPolicyTest
```

Expected: queued mode preserves completed sibling work and sync retry docs are explicit.

## Task 8: Tool Names, Runtime Options, And Delay Scheduler Resolution

**Files:**
- Add: `src/LaravelAi/ToolName.php`
- Modify: `src/LaravelAi/GraphTool.php`
- Modify: `src/LaravelAi/DurableGraphTool.php`
- Add: `src/Runtime/RuntimeOptions.php`
- Modify: `src/Runtime/PendingGraphRun.php`
- Modify: `src/Runtime/DurableGraphSession.php`
- Modify: `src/Runtime/GraphRuntime.php`
- Add: `src/Support/DelaySchedulerResolver.php`
- Modify: `src/AgentGraphServiceProvider.php`
- Test: `tests/Feature/LaravelAiIntegrationTest.php`
- Test: `tests/Feature/DurableGraphToolTest.php`
- Test: `tests/Feature/DelayInterruptTest.php`

- [ ] **Step 1: Write tool name tests**

Add:

```php
it('sanitizes default graph tool names for provider compatibility', function () {
    $tool = AgentGraph::tool('filament-agentic-chatbot.workflow.123/alpha');

    expect($tool->name())->toBe('run_filament_agentic_chatbot_workflow_123_alpha');
});

it('rejects invalid custom tool names', function () {
    expect(fn () => AgentGraph::tool('support')->name('bad name with spaces'))
        ->toThrow(InvalidArgumentException::class, 'Invalid AI tool name');
});
```

- [ ] **Step 2: Implement `ToolName` helper**

Create `src/LaravelAi/ToolName.php`:

```php
<?php

namespace Heiner\AgentGraph\LaravelAi;

use InvalidArgumentException;

final class ToolName
{
    public static function fromGraphKey(string $prefix, string $graphKey): string
    {
        $name = strtolower($prefix.'_'.preg_replace('/[^A-Za-z0-9_]+/', '_', $graphKey));
        $name = trim(preg_replace('/_+/', '_', $name), '_');

        if (strlen($name) <= 64) {
            return self::assertValid($name);
        }

        $hash = substr(hash('xxh128', $name), 0, 8);

        return self::assertValid(substr($name, 0, 55).'_'.$hash);
    }

    public static function assertValid(string $name): string
    {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $name)) {
            throw new InvalidArgumentException('Invalid AI tool name. Use 1-64 characters: letters, numbers, underscore, or hyphen, starting with a letter.');
        }

        return $name;
    }
}
```

- [ ] **Step 3: Use helper in tools**

In `GraphTool::__construct()`:

```php
$this->name = ToolName::fromGraphKey('run', $graphKey);
```

In `DurableGraphTool::__construct()`:

```php
$this->name = ToolName::fromGraphKey('durable', $graphKey);
```

In `name()` setters:

```php
$this->name = ToolName::assertValid($name);
```

- [ ] **Step 4: Add runtime options**

Create `src/Runtime/RuntimeOptions.php`:

```php
<?php

namespace Heiner\AgentGraph\Runtime;

final class RuntimeOptions
{
    public function __construct(
        public readonly ?int $maxSteps = null,
    ) {}

    public static function fromArray(array $options = []): self
    {
        return new self(
            maxSteps: isset($options['max_steps']) ? (int) $options['max_steps'] : null,
        );
    }

    public function maxSteps(): int
    {
        return $this->maxSteps ?? (int) config('agent-graph.max_steps', 100);
    }
}
```

Thread it through `PendingGraphRun`, `DurableGraphSession`, and `GraphRuntime::run()` / `resume()` / `continueLocked()` so plugins can set per-run `max_steps` without mutating global config.

- [ ] **Step 5: Resolve delay scheduler lazily**

Create `src/Support/DelaySchedulerResolver.php`:

```php
<?php

namespace Heiner\AgentGraph\Support;

use Heiner\AgentGraph\Contracts\DelayScheduler;

final class DelaySchedulerResolver
{
    public function resolve(): DelayScheduler
    {
        return app(DelayScheduler::class);
    }
}
```

Change `GraphRuntime` to accept `DelaySchedulerResolver $delaySchedulers` instead of `?DelayScheduler $delayScheduler`. Then:

```php
protected function delayScheduler(): DelayScheduler
{
    return $this->delaySchedulers->resolve();
}
```

This makes post-resolution container rebinding deterministic for plugin integrations.

- [ ] **Step 6: Verify**

Run:

```bash
composer test -- --filter=LaravelAiIntegrationTest
composer test -- --filter=DurableGraphToolTest
composer test -- --filter=DelayInterruptTest
```

Expected: adapter behavior remains compatible except safer default tool names.

## Task 9: Doctor Command As Production Safety Gate

**Files:**
- Modify: `src/Console/DoctorCommand.php`
- Modify: `tests/Feature/ConsoleCommandsTest.php`
- Modify: `README.md`
- Modify: `docs/guides/production.md`

- [ ] **Step 1: Add doctor output expectations**

Extend `tests/Feature/ConsoleCommandsTest.php` to assert output includes:

```text
Store driver
Database connection
Cache locks
Lock fail-closed
Execution mode
Queue connection
Queue name
Task lease seconds
Node lease seconds
Max steps
```

- [ ] **Step 2: Add status helpers**

In `DoctorCommand`, implement:

```php
protected function pass(string $message): void
{
    $this->info('PASS '.$message);
}

protected function warnStatus(string $message): void
{
    $this->warn('WARN '.$message);
}

protected function failStatus(string $message): void
{
    $this->error('FAIL '.$message);
}
```

- [ ] **Step 3: Add production checks**

Checks:

```text
database tables present
cache lock provider available
fail_without_provider=true unless app.env is local/testing
AGENT_GRAPH_STORE=database outside local/testing
queued_supersteps has queue connection or clear default queue
node_executions table present when queued_supersteps is active
lock ttl >= node lease seconds
task lease seconds > 0
max_steps > 0
```

- [ ] **Step 4: Verify**

Run:

```bash
composer test -- --filter=ConsoleCommandsTest
```

Expected: doctor tests pass and output is actionable.

## Task 10: Documentation, Upgrade Notes, And Public Contract Review

**Files:**
- Modify: `README.md`
- Modify: `UPGRADE.md`
- Modify: `ROADMAP.md`
- Modify: `docs/api-reference.md`
- Modify: `docs/guides/production.md`
- Modify: `docs/concepts/state-graphs.md`
- Modify: `docs/concepts/interrupts.md`
- Modify: `docs/concepts/checkpoints.md`
- Modify: `docs/reference-sources.md`

- [ ] **Step 1: Update reference sources**

Update LangGraph entry in `docs/reference-sources.md` with the current inspection:

```text
Inspected commit: 1fcb768
Date inspected: 2026-05-30
Purpose: StateGraph validation, multiple START edges, pending writes, Command/Send, interrupt resume, checkpoint saver contracts.
```

- [ ] **Step 2: Update public semantics**

Document:

```text
Multiple START edges execute as the first superstep.
Unknown reducer strings throw.
Only concurrency limit=1 is supported.
Cache locks fail closed by default.
Resume, state-edit resume, cancel, queued continuation, and delayed continuation are run-lock protected.
Queue jobs have package defaults and tags.
```

- [ ] **Step 3: Add migration notes**

In `UPGRADE.md`, include:

```text
Run the new invariant migration after upgrading.
Clean duplicate checkpoint rows per run+step before adding the unique index if an application has historical duplicates.
Resolve or expire duplicate pending interrupts before deploying the invariant change.
```

- [ ] **Step 4: Verify docs contain no stale claims**

Run:

```bash
rg -n "limit: 5|semaphore|silently|first entry|unknown reducer|fail_without_provider|queued_supersteps" README.md UPGRADE.md ROADMAP.md docs
```

Expected: search output points to intentional docs, not stale behavior.

## Task 11: Package-Wide Verification

**Files:**
- No source edits required.

- [ ] **Step 1: Run full test suite**

Run:

```bash
composer test
```

Expected: all Pest tests pass.

- [ ] **Step 2: Run static analysis**

Run:

```bash
composer test:types
```

Expected: PHPStan passes.

- [ ] **Step 3: Run style check**

Run:

```bash
composer test:lint
```

Expected: Pint reports no style changes needed.

- [ ] **Step 4: Run full package check**

Run:

```bash
composer check
```

Expected: lint, tests, and static analysis all pass.

## Deferred Plugin Work

These audit items require a separate `filament-agentic-chatbot` checkout:

- Bind plugin `DelayScheduler` before `GraphRuntime` is resolved or consume the new resolver.
- Replace global `agent-graph.max_steps` mutation with per-run runtime options.
- Ensure delay fallback uses `AgentGraphQueue::configure()`.
- Align workflow editor UI and compiler semantics for multiple trigger outputs.
- Correct plugin README compatibility with `laravel/ai` constraints.
- Add workflow projection tests for delay, interrupt bubbling, max steps, and side-effect idempotency.

## Recommended Execution Order

1. Task 1: lock fail-closed and doctor baseline.
2. Task 2: atomic resume, state edit resume, and cancel.
3. Task 3: strict reducers and concurrency contract.
4. Task 4: multiple START edges.
5. Task 5: dynamic target validation.
6. Task 6: queue defaults and database invariants.
7. Task 7: pending writes recovery coverage.
8. Task 8: tool names, runtime options, scheduler resolver.
9. Task 9: full doctor command.
10. Task 10: docs and upgrade notes.
11. Task 11: full verification.

The first implementation feature should be Task 1 because it is small, high-impact, and gives production users an immediate safety improvement before the deeper runtime refactor.
