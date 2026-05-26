# AgentGraph Child Run Lineage Metadata Checklist

Goal: add a generic parent/child run lineage foundation through run metadata and read-only inspection APIs.

## Scope

- [x] Work on `codex/agentgraph-child-run-metadata`.
- [x] Keep the feature metadata-only: no subgraph orchestration, no streaming transport, no migrations.
- [x] Store lineage under `run.meta.parent`.
- [x] Preserve existing `meta.time_travel` lineage behavior.

## TDD Steps

- [x] Add failing feature tests for manual child runs, metadata merge behavior, listing order/limits, and invalid parent metadata.
- [x] Add failing time-travel assertions for replay/fork parent metadata and child-run listing.
- [x] Add failing database-store assertions for child-run listing parity.
- [x] Implement minimal runtime, manager, result, snapshot, and store changes.
- [x] Run targeted tests until green.

## Docs

- [x] Update README usage examples.
- [x] Update API reference.
- [x] Update conceptual docs for child-run lineage boundaries.
- [x] Update SDK extension tracker.
- [x] Update changelog and upgrade notes.

## Verification

- [x] `composer validate --strict`
- [x] `composer test:lint`
- [x] `composer test`
- [x] `composer test:types`
- [x] `composer check`
- [x] `git diff --check`
- [x] Local commit: `feat: add child run lineage metadata`
