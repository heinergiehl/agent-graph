# Memory

Memory is separate from checkpoints. Checkpoints preserve execution state; memory preserves durable context for future runs.

The MVP supports scoped database memory with exact key lookup, namespace lookup, keyword search, type filtering, metadata, confidence, source, usage counts, and TTL fields.

Scopes:

- `run`
- `thread`
- `actor`
- `tenant`
- `application`
- `global`

Semantic/vector memory is intentionally deferred to an optional adapter.
