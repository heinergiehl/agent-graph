# AgentGraph 0.14 Runtime Contracts Design

**Goal:** Add generic SDK features that make AgentGraph more useful as a Laravel-native durable workflow runtime without competing with Laravel AI provider, tool, streaming, or structured-output APIs.

**Scope:** This release focuses on typed interrupt contracts, graph manifests, graph validation, schema-derived graph tools, and production-readiness checks. Product-specific Filament UI behavior stays in consuming apps.

## Runtime Contracts

AgentGraph interrupts remain backward compatible with `NodeResult::interrupt($type, $payload)`, but the SDK also exposes a typed `InterruptContract` for common human-in-the-loop waitpoints. The contract serializes a stable payload with `contract_version`, `node_id`, `output`, and an `interaction` block. Initial kinds are `slot_value`, `approval`, and `choice`; apps may still pass custom payloads through normal interrupts.

## Graph Manifests

Compiled graph definitions expose a read-only manifest suitable for tools, validators, inspectors, and visual editors. The manifest includes graph key/version, normalized state schema, reducers, node ids/classes, edges, conditional routes, and node policies. It does not serialize closures or product UI.

## Validation

A `GraphValidator` performs release-time checks that are stricter than topology compile checks: known state schema types, known reducer names, and unreachable nodes. Reachability follows runtime routing precedence, so conditional routes replace static outgoing edges for the same node. The validator returns a report with errors and warnings instead of throwing immediately, so `agent-graph:validate` and consuming apps can display actionable diagnostics.

## Tool Schemas

`GraphTool::schema()` derives optional `input` object properties from the registered graph state schema when the graph is available. This makes graph-as-tool calls more reliable for Laravel AI agents without marking internal state channels as required public input. If the graph is not registered yet, the tool keeps the previous generic `input: object` fallback. `GraphTool::schemaInput()` lets apps expose a deliberately bounded public input contract.

## Production Readiness

The release adds CI security audit coverage and keeps future recovery/deep doctor checks as a generic SDK concern. No Filament-specific code belongs in AgentGraph core.
