# AgentGraph 0.15 Contracts Validation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prepare AgentGraph 0.15 with production-grade graph contracts, schema export, CLI validation, and typed interrupt response validation.

**Architecture:** Add a neutral `GraphSchemaExporter` as the exact schema source for manifests and downstream adapters. Keep runtime resume compatibility by adding explicit contract-aware validation while preserving permissive `resume()` behavior. Extend validator reports and CLI output additively so existing text output remains usable and JSON/strict modes are stable for CI.

**Tech Stack:** PHP 8.3, Laravel 12/13 package APIs, Pest, PHPStan, Pint, Composer scripts.

---

### Task 1: Neutral State Schema Export

**Files:**
- Create: `src/Graph/GraphSchemaExporter.php`
- Modify: `src/Graph/GraphManifest.php`
- Modify: `src/LaravelAi/GraphTool.php`
- Test: `tests/Feature/GraphContractsTest.php`
- Test: `tests/Unit/StateSchemaValidatorTest.php`

- [x] Write failing tests for exact normalized schema export covering primitive unions, nullable values, enums, arrays, nested objects, and GraphTool compatibility.
- [x] Implement `GraphSchemaExporter::state(array $schema): array` and `GraphSchemaExporter::channel(string|array $definition): array`.
- [x] Refactor `GraphManifest` and `GraphTool` to use the exporter without changing GraphTool's provider-compatible fallback behavior.
- [x] Run focused graph contract and schema validator tests.

### Task 2: Graph Manifest v2

**Files:**
- Modify: `src/Graph/StateGraph.php`
- Modify: `src/Graph/GraphDefinition.php`
- Modify: `src/Graph/GraphManifest.php`
- Test: `tests/Feature/GraphContractsTest.php`
- Test: `tests/Unit/StateGraphTest.php`

- [x] Write failing tests for `manifest_version`, node metadata, input/output channels, `can_interrupt`, and `side_effects`.
- [x] Add neutral node metadata builder API on `StateGraph` and immutable accessors on `GraphDefinition`.
- [x] Include v2 manifest fields additively without Filament, UI, or product-specific classes.
- [x] Run focused manifest tests.

### Task 3: GraphValidator 2.0 and CLI Modes

**Files:**
- Modify: `src/Graph/GraphValidator.php`
- Modify: `src/Graph/GraphValidationReport.php`
- Modify: `src/Console/ValidateCommand.php`
- Test: `tests/Feature/GraphContractsTest.php`
- Test: `tests/Feature/ConsoleCommandsTest.php`

- [x] Write failing tests for terminal path warnings, conditional without default warnings, mixed static and conditional outgoing warnings, strict warning failure, and JSON output.
- [x] Add stable report metadata and strict failure behavior.
- [x] Extend `agent-graph:validate` with `--strict` and `--json` while preserving existing text output.
- [x] Run focused validator and console tests.

### Task 4: Typed Interrupt Response Validation

**Files:**
- Modify: `src/Graph/InterruptContract.php`
- Modify: `src/Runtime/GraphRuntime.php`
- Modify: `src/AgentGraphManager.php`
- Modify: `src/Facades/AgentGraph.php` if facade docblock generation requires explicit method docs.
- Test: `tests/Feature/GraphContractsTest.php`
- Test: `tests/Feature/RuntimeHardeningTest.php`
- Test: `tests/Feature/StateSchemaValidationTest.php`

- [x] Write failing approval, choice, and slot-value resume validation tests.
- [x] Add response schema metadata to `InterruptContract` payloads and validation helpers.
- [x] Add a contract-aware resume API that validates pending typed interrupt payloads before resolving the interrupt.
- [x] Preserve existing `resume()` and `resumeStrict()` compatibility unless the new contract-aware API is used.
- [x] Run focused interrupt and runtime tests.

### Task 5: Documentation, Version Metadata, and Full Verification

**Files:**
- Modify: `README.md`
- Modify: `UPGRADE.md`
- Modify: `CHANGELOG.md`
- Modify: `ROADMAP.md`
- Modify: `docs/api-reference.md`
- Modify: `docs/concepts/interrupts.md`
- Modify: `docs/guides/production.md`
- Modify: `composer.json`

- [x] Update public documentation for 0.15 and branch alias.
- [x] Run `composer test:lint`, `composer test`, `composer test:types`, and `composer check`.
- [x] Review the final diff for API cleanliness, generic SDK boundaries, and backward compatibility.
- [x] Commit all completed changes.
