# Pending Delay Recovery

Available in stable **0.16.1**; it was introduced and first verified in RC2, and its runtime behavior is unchanged by the migration-only patch. See the [current release notes](../releases/v0.16.1.md), the [0.16.0 notes](../releases/v0.16.0.md), and the [RC2 notes](../releases/v0.16.0-rc.2.md) for the respective verification scopes.

## Failure and recovery boundary

The runtime commits a delay's checkpoint, writes, interrupt, and `delayed` run status in one database transaction. It asks `DelayScheduler` to arrange delivery only after that commit. A process or queue failure between those operations can leave a valid wait without a deliverable job. A queue can also acknowledge a job that is subsequently lost.

`AgentGraph::recover($runId)` can request delivery again from this persisted state. It uses the existing run lock and the currently bound `DelayScheduler`. It does not introduce a scheduler, queue, or second owner of workflow state.

```php
// Register the original graph version in this worker before recovery.
AgentGraph::define($originalGraphDefinition);
$waiting = AgentGraph::recover($runId);
```

For a valid delay, the returned result still has status `delayed`. Recovery does not mean that the job has already executed or that its transport is healthy.

## Durable contract

- The run, latest checkpoint, current checkpoint pointer, and pending delay interrupt must agree. The checkpoint must identify the same waiting node and graph/thread, and the registered graph version must match the saved run and checkpoint.
- The interrupt's normalized absolute `payload.resume_at` is the due-time authority. Recovery does not calculate a new delay from the current clock or fall back to a different timestamp.
- Every request retains the existing interrupt ID and due time. Repeated recovery may schedule duplicate delivery requests. A due time that has passed remains the original due time.
- Recovery does not add checkpoints, writes, node executions, or interrupts, change the run status, resolve an interrupt, or replay node/checkpoint/interrupt observers.
- Scheduler exceptions remain visible to the caller. They do not invalidate the committed wait; a later recovery attempt can retry delivery.
- Ordinary input waits and terminal runs are returned unchanged without scheduling. Inconsistent delay records fail closed and require reconciliation.

## Scheduler and host responsibilities

Custom `DelayScheduler` implementations must safely accept repeated calls for the same `(runId, interrupt_id)`. They may use that identity for delivery deduplication, but must not permanently suppress replacement delivery merely because an earlier attempt was acknowledged. Delivery must still be able to recover from a lost job.

Use an asynchronous queue that honors the supplied due time. The default scheduler submits `ContinueDelayedGraphJob` on the configured AgentGraph connection and queue. Delivery goes through the existing guarded runtime resume path; terminal runs and jobs for older interrupts are ignored by the default job.

This slice does not add background discovery of stranded runs. The host/operator must invoke recovery for the affected run and implement bounded recovery cadence. It does not change child-run ownership, cascade cancellation, deployment policy, or model behavior.

Due-time enforcement still belongs to the scheduler/transport. The existing job handler does not independently reject an early manual invocation. Laravel `sync` and `deferred` queues do not provide durable delayed delivery and do not meet this scheduler contract. The runtime does not detect or reject those backends; inline resumption can also conflict with the recovery run lock. Tests before, at, and after the due time verify the timestamp submitted to the scheduler; they do not certify a real queue worker's timing behavior.

Legacy 0.15.1 checkpoints did not persist the `runtime.wait` marker required here. Missing markers are treated as an unsupported recovery boundary, not proof that the original wait was corrupted. Recovery does not synthesize the marker or rewrite historical records; apply the host's explicit reconciliation or migration procedure.

External side effects still need stable operation identities, provider idempotency where available, and reconciliation for unknown outcomes. Repeatable scheduling is not an exactly-once guarantee for remote operations.

## Reference and verification

The design was compared with LangGraph commit [`11ee185999b86bfea2d8c0e69cef9a5e37acf686`](https://github.com/langchain-ai/langgraph/commit/11ee185999b86bfea2d8c0e69cef9a5e37acf686), rather than treating its moving main branch as a fixed specification:

- [Pending interrupt IDs are derived from persisted interrupt/resume writes](https://github.com/langchain-ai/langgraph/blob/11ee185999b86bfea2d8c0e69cef9a5e37acf686/libs/langgraph/langgraph/pregel/_loop.py#L818-L844).
- [Recovery tests preserve already successful work](https://github.com/langchain-ai/langgraph/blob/11ee185999b86bfea2d8c0e69cef9a5e37acf686/libs/langgraph/tests/test_pregel.py#L942-L987).
- [Interrupts require explicit resume and re-enter their node](https://github.com/langchain-ai/langgraph/blob/11ee185999b86bfea2d8c0e69cef9a5e37acf686/libs/langgraph/langgraph/types.py#L851-L871).

Those references support recovery from durable state and explicit execution boundaries. They do not establish a timer-delivery guarantee in LangGraph core; this Laravel SDK uses its own existing scheduler contract.

`tests/Feature/DelayRecoveryTest.php` explicitly binds SQL-backed stores because normal SDK tests substitute in-memory stores. It covers failed scheduling after commit in synchronous and queued-superstep execution, fresh store/runtime instances, lost acknowledged jobs, repeated recovery, original due times, duplicate and stale jobs, ordinary input waits, terminal runs, and rejection of mismatched durable authority.

Run the focused checks with:

```bash
php vendor/bin/pest tests/Feature/DelayRecoveryTest.php tests/Feature/DelayInterruptTest.php tests/Feature/RuntimeRecoveryTest.php tests/Feature/QueueHardeningTest.php
```

These tests use SQLite and a fake queue. Actual queue transport, cross-process contention, other database engines, and additional consuming-plugin integrations remain separate verification gates.

The [RC2 release smoke](../releases/v0.16.0-rc.2.md#verification) additionally exercised fresh PHP recovery processes and real Laravel database-queue workers against an isolated SQLite app. Both synchronous and queued-superstep graphs preserved the wait and original due time, ignored pre-due work, and completed once after duplicated replacement delivery. That check does not certify other queue transports, concurrent-worker races, other database engines, or consuming-plugin integration.
