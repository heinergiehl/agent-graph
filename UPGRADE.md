# Upgrade Guide

## 0.9 Beta To v1

AgentGraph 0.9 is a public beta. Breaking changes are allowed before v1 when they improve the final stable API.

Expected v1 hardening areas:

- Public method names and return payloads for `StateGraph`, `NodeContext`, `NodeResult`, `AgentNode`, `GraphTool`, and store contracts.
- Interrupt payload shape for chatbot UI integration.
- Memory fallback defaults and tenant/actor examples.
- Queue job payloads for delayed continuation.

Before upgrading to v1:

1. Read `CHANGELOG.md` for breaking changes.
2. Run `php artisan agent-graph:doctor`.
3. Run your graph and interrupt/resume flows against a staging database.
4. Verify idempotent task keys for external side effects.
5. Re-run any chatbot integration tests that consume `GraphTool` JSON.
