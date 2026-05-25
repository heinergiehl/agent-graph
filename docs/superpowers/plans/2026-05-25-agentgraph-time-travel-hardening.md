# AgentGraph Time-Travel Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden the current time-travel implementation with state type validation, graph version checks, lineage inspection, replay-safety documentation, and full verification.

**Architecture:** Keep existing `checkpoint()`, `replay()`, and `fork()` APIs compatible. Add small internal validators and store queries without a migration. Implement each change test-first with targeted verification before moving on.

**Tech Stack:** PHP 8.3+, Laravel package, Pest, PHPStan, Pint, in-memory and database stores.

---

## Tasks

### Task 1: Baseline

- [ ] Confirm branch `codex/agentgraph-time-travel`.
- [ ] Run `composer test -- tests/Feature/TimeTravelTest.php tests/Integration/DatabaseStoresTest.php --stop-on-failure`.

### Task 2: State Schema Validator

- [ ] Add `tests/Unit/StateSchemaValidatorTest.php`.
- [ ] Add `src/State/StateSchemaValidator.php`.
- [ ] Validate simple schema types, nullable unions, strict unknown keys, and non-strict compatibility mode.
- [ ] Run `composer test -- tests/Unit/StateSchemaValidatorTest.php --stop-on-failure`.

### Task 3: Runtime Schema Validation

- [ ] Add `tests/Feature/StateSchemaValidationTest.php`.
- [ ] Validate `run()` input strictly before run creation.
- [ ] Validate `resume()` payload non-strict after removing `interrupt_id`.
- [ ] Validate `resumeWithStateEdit()` and `fork()` strictly before mutation.
- [ ] Validate node writes strictly and fail the run on invalid writes.
- [ ] Run targeted schema validation and runtime regression tests.

### Task 4: Graph Version Compatibility

- [ ] Add `tests/Feature/TimeTravelVersionCompatibilityTest.php`.
- [ ] Fail `resume()`, `resumeWithStateEdit()`, `replay()`, and `fork()` when persisted graph version differs from registered graph version.
- [ ] Run targeted version compatibility and queue/time-travel regressions.

### Task 5: START/END Fork Endpoints

- [ ] Extend `tests/Feature/TimeTravelTest.php` for `asNode: StateGraph::START`, `asNode: StateGraph::END`, and unknown endpoint.
- [ ] Add `GraphDefinition::hasEndpoint()` and `GraphDefinition::successorsOf()`.
- [ ] Use endpoint helpers in `GraphRuntime::fork()`.
- [ ] Run `composer test -- tests/Feature/TimeTravelTest.php --stop-on-failure`.

### Task 6: Time-Travel Lineage API

- [ ] Add `tests/Feature/TimeTravelLineageTest.php`.
- [ ] Extend `tests/Integration/DatabaseStoresTest.php`.
- [ ] Add `RunStore::listTimeTravelChildren(string $checkpointId, int $limit = 50): array`.
- [ ] Implement in database and in-memory stores.
- [ ] Add `AgentGraph::timeTravelChildren()` via manager and runtime.
- [ ] Run targeted lineage and inspection regressions.

### Task 7: Replay-Safety Docs

- [ ] Update README examples and side-effect warning.
- [ ] Update checkpoint concept docs for source-lineage versus parent checkpoint chain.
- [ ] Update production guide with replay/fork safety guidance.
- [ ] Update roadmap hardening status.
- [ ] Run `composer test:lint`.

### Task 8: Final Verification and Commit

- [ ] Run `composer test`.
- [ ] Run `composer test:types`.
- [ ] Run `composer test:lint`.
- [ ] Run `composer check`.
- [ ] Run `git diff --check`.
- [ ] Run targeted regression command from the user plan.
- [ ] Stage all changed files.
- [ ] Commit as `feat: harden agentgraph time travel`.
