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
- retries do not duplicate completed side effects
