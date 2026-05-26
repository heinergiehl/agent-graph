# Memory

Memory is separate from checkpoints. Checkpoints preserve execution state; memory preserves durable context for future runs.

The MVP supports scoped database memory with exact key lookup, namespace listing, keyword search, type filtering, metadata, confidence, source, usage counts, and TTL fields.

Scopes:

- `run`
- `thread`
- `actor`
- `tenant`
- `application`
- `global`

Semantic/vector memory is intentionally deferred to an optional adapter.

Applications that need UI/debug inspection can resolve `EnumerableMemoryStore` and call `listNamespace($scopes, $namespace)`. Listing follows the configured memory fallback order and omits expired records without incrementing usage counters.
