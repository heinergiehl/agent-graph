# AgentGraph 0.12 Release Hardening Checklist

Goal: merge the SDK core extensions into `main`, create `codex/agentgraph-0-12-hardening`, and make the 0.12 beta release language, docs, and metadata consistent without adding runtime features.

## Merge And Branch

- [x] Verify `codex/agentgraph-node-policies` with `composer check`.
- [x] Verify clean diff with `git diff --check`.
- [x] Fast-forward `main` to `codex/agentgraph-node-policies`.
- [x] Run `composer check` on merged `main`.
- [x] Create `codex/agentgraph-0-12-hardening`.

## Release Hardening

- [x] Keep active release line as 0.12 beta, not `1.0-dev`.
- [x] Update Composer branch alias to `0.12-dev`.
- [x] Align README, roadmap, changelog, and upgrade guide language around 0.12 beta and later v1 stabilization.
- [x] Clarify CI compatibility: PHP 8.3/8.4, Laravel 12/13, `laravel/ai ^0.7`; keep `laravel/ai ^1.0` non-blocking until upstream tags it.
- [x] Add tenant and actor memory examples.
- [x] Confirm API reference remains documentation-only and does not introduce new runtime APIs.
- [x] Resolve Composer audit advisory for `symfony/polyfill-intl-idn` with a targeted patch-level dependency update.

## Verification

- [x] `composer validate --strict`
- [x] `composer audit`
- [x] `composer test:lint`
- [x] `composer test`
- [x] `composer test:types`
- [x] `composer check`
- [x] `git diff --check`
- [x] Commit as `chore: harden agentgraph 0.12 beta release`

## Explicit Non-Goals

- No subgraphs.
- No AgentGraph-owned streaming transport.
- No semantic/vector memory adapter.
- No database migrations.
- No Laravel AI provider hooks.
