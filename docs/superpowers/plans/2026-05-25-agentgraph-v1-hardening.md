# AgentGraph v1-Härtung Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden AgentGraph's v1 SDK core with runtime inspection, explicit state-edit resume, queue retry guards, and documentation.

**Architecture:** Add a read-only inspection layer over existing stores without schema changes. Keep normal resume compatible, and add a stricter explicit state-edit resume path for human-in-the-loop state corrections. Queue hardening is handled through delayed-job guards plus runtime validation.

**Tech Stack:** PHP 8.3, Laravel package conventions, Pest, Orchestra Testbench, existing AgentGraph store contracts.

---

### Task 1: Runtime Inspection

**Files:**
- Create: `src/Runtime/RunSnapshot.php`
- Modify: `src/AgentGraphManager.php`
- Modify: `src/Runtime/GraphRuntime.php`
- Modify: `src/Contracts/RunStore.php`
- Modify: `src/Contracts/InterruptStore.php`
- Modify: database and in-memory run/interrupt stores
- Test: `tests/Feature/RuntimeInspectionTest.php`

- [ ] Write failing feature tests for completed, interrupted, failed, delayed, history, traces, and run listing.
- [ ] Implement `RunSnapshot`.
- [ ] Add `inspect()` and `runs()` to manager/runtime.
- [ ] Add `list()` to run stores and `listForRun()` to interrupt stores.
- [ ] Verify runtime inspection tests pass.

### Task 2: State-Edit Resume

**Files:**
- Modify: `src/AgentGraphManager.php`
- Modify: `src/Runtime/GraphRuntime.php`
- Test: `tests/Feature/StateEditResumeTest.php`

- [ ] Write failing tests for valid state edit, unknown keys, wrong/stale interrupt IDs, and normal resume compatibility.
- [ ] Implement `resumeWithStateEdit()`.
- [ ] Validate state patch keys against graph schema before resolving the interrupt.
- [ ] Require the pending interrupt to match the supplied run and interrupt ID.
- [ ] Verify state-edit tests pass.

### Task 3: Queue Retry Hardening

**Files:**
- Modify: `src/Queue/ContinueDelayedGraphJob.php`
- Modify: `src/Runtime/GraphRuntime.php`
- Test: `tests/Feature/QueueHardeningTest.php`

- [ ] Write failing tests for final run statuses, duplicate delayed jobs, cancelled runs, and latest-checkpoint resume.
- [ ] Add delayed-job no-op guards for final statuses and missing/mismatched pending delay interrupts.
- [ ] Keep manual resume strict when an interrupt ID does not match a pending interrupt.
- [ ] Verify queue hardening tests pass.

### Task 4: Docs and Verification

**Files:**
- Modify: `README.md`
- Modify: `docs/guides/production.md`
- Modify: `ROADMAP.md`

- [ ] Document `inspect()`, `runs()`, and `resumeWithStateEdit()`.
- [ ] Document queue/retry and human-in-the-loop production guidance.
- [ ] Update ROADMAP v1 hardening status.
- [ ] Run `composer test`, `composer test:types`, `composer test:lint`, and `composer check`.
