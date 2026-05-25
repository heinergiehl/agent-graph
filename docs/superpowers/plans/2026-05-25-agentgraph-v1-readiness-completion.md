# AgentGraph v1 Readiness Completion Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete v1 release readiness documentation and verification for the existing hardened AgentGraph runtime core.

**Architecture:** Do not add new runtime capabilities in this batch. Document the stable and experimental public API surface, update release and upgrade notes, close the roadmap around v1 readiness, run full verification, and commit the documentation-only finish.

**Tech Stack:** PHP 8.3+, Laravel package, Pest, PHPStan, Pint.

---

## Tasks

- [ ] Confirm branch `codex/agentgraph-time-travel` is clean at `8274758`.
- [ ] Run targeted baseline for runtime inspection, schema validation, time travel, and lineage.
- [ ] Add `docs/api-reference.md`.
- [ ] Link the API reference from `README.md`.
- [ ] Expand `CHANGELOG.md` v1 unreleased notes.
- [ ] Rewrite `UPGRADE.md` with concrete 0.9-to-v1 guidance.
- [ ] Update `ROADMAP.md` so completed v1 hardening is no longer listed as open work.
- [ ] Run `composer test`, `composer test:types`, `composer test:lint`, `composer check`, `git diff --check`, and targeted v1 regression tests.
- [ ] Stage the docs and commit as `docs: complete agentgraph v1 readiness`.
