# Idempotent Tasks

Every external side effect should run through the task API.

```php
$result = $context->tasks()->once(
    key: "crm-update:{$customerId}:{$context->runId()}",
    input: $payload,
    handler: fn () => $crm->updateCustomer($customerId, $payload),
);
```

Guarantees:

- completed task keys return stored results
- reused keys with different input hashes are rejected
- failed tasks preserve errors for debugging
- queue retries, node retry policies, replay, and fork do not duplicate completed side effects

Inspect tasks without mutating them:

```php
$tasks = AgentGraph::tasks([
    'run_id' => $runId,
    'node_id' => 'sync_crm',
], limit: 50);
```

The read API supports `run_id`, `node_id`, `checkpoint_id`, and `status` filters.
