# AgentGraph 0.12 Release Finish Checklist

Goal: finish local 0.12 release readiness after merging the SDK core extensions and 0.12 hardening into `main`.

## Completed Locally

- [x] Fast-forwarded `main` to include SDK core extensions.
- [x] Fast-forwarded `main` to include 0.12 hardening.
- [x] Ran `composer validate --strict`.
- [x] Ran `composer audit`.
- [x] Ran `composer check` on merged `main`.
- [x] Confirmed no AgentGraph-owned streaming transport is introduced.
- [x] Kept `laravel/ai ^1.0` non-blocking until upstream tags a stable release.
- [x] Marked `CHANGELOG.md` 0.12.0 with the release date.
- [x] Updated sandbox validation notes to point consumers at 0.12.0 instead of 0.9 beta.

## Before Tagging 0.12.0

- [ ] Run the GitHub Actions matrix on the remote branch: PHP 8.3/8.4, Laravel 12/13, `laravel/ai ^0.7`.
- [ ] Run the Filament Agentic Chatbot integration against the local package path.
- [ ] Verify real app flows: interrupt/resume, `GraphTool`, `AgentNode` streaming, memory scopes, timeline, and idempotent tasks.
- [ ] Review `docs/api-reference.md` for final public API naming before v1.
- [ ] Create and push tag `v0.12.0` only after remote CI and integration checks pass.

## Next Feature Branches

- [ ] `codex/agentgraph-child-run-metadata`
- [ ] `codex/agentgraph-subgraph-composition`
- [ ] `codex/agentgraph-semantic-memory-adapter`
- [ ] `codex/agentgraph-inspector-read-models`

## Explicit Boundaries

- No new runtime features in the release-finish branch.
- No push or tag from this local finish step.
- No Filament-specific code in AgentGraph core.
