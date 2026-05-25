# Production

Use database stores as the source of truth. Cache/Redis locks are acceleration and duplicate-execution protection, not durable storage.

Recommended production settings:

- publish and run migrations
- configure queue workers
- define tenant-aware memory scopes
- keep trace redaction keys updated
- prune traces and old runs according to your retention policy
- wrap every external side effect in `$context->tasks()->once()`
- avoid storing raw secrets in state, memory, traces, task input, or interrupt payloads
