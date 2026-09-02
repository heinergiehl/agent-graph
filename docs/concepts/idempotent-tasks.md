# Idempotent Tasks

Use `$context->tasks()->once()` to persist an external operation's result under a stable key. The public API is unchanged in stable 0.16.0:

```php
$result = $context->tasks()->once(
    key: "crm-update:{$tenantId}:{$operationId}",
    input: $payload,
    handler: fn () => $crm->updateCustomer($customerId, $payload),
);
```

Choose `$operationId` once for the intended business operation and reuse it on retries. Include the tenant or another appropriate scope so unrelated operations cannot share a key.

## Stored-result and ownership guarantees

With the 0.16 database or in-memory stores:

- A completed key with the same input hash returns its stored result without running the handler again.
- Reusing a key with different input is rejected.
- Claiming an available attempt is atomic. An unexpired running lease prevents another claim for the same key.
- Each new claim increments `attempts`. Only the matching running attempt can complete or fail; a late worker cannot overwrite a replacement attempt.
- A failed attempt retains its error until it is reclaimed. A new attempt starts with a cleared error.
- A throwing `GraphTaskCompleted` listener does not downgrade a completed receipt. The exception can still propagate to the caller or fail its node.

These guarantees apply while the task record is retained. Pruning or deleting a receipt removes its stored-result protection.

## External effects and unknown outcomes

`once()` does not make a remote operation and the local database commit one transaction. An API call can succeed just before PHP exits or storing its receipt fails. A lease can also expire while the original remote request is still running. Ownership checks protect persisted task results; they cannot cancel or undo that request.

Use the same stable operation key for provider idempotency when supported. If the outcome is unknown, inspect task and provider records before retrying. Do not assume a missing result or expired lease means the external effect did not happen.

Set `agent-graph.tasks.lease_seconds` longer than the expected operation duration. A lease is not a replacement for provider idempotency or reconciliation.

## Retries, replay, and fork

Node and queue retries reuse a receipt only when they use the same task key and input hash.

Replay and fork create new runs. A key such as `"crm-update:{$customerId}:{$context->runId()}"` changes with the run ID, so a fork can legitimately perform a new operation. If the same business operation must remain deduplicated across runs, preserve an operation identity across those runs and use it in the key instead. Changed input requires a new intentional operation; do not silently reuse or rewrite an old receipt.

## Custom task stores

0.16 changes the `TaskStore` adapter contract:

```php
public function complete(string $key, int $attempt, mixed $result): array;
public function fail(string $key, int $attempt, string $message, array $meta = []): array;
```

`start()` must atomically return a completed record or claim an available attempt. Pass the `attempts` value returned by that claim to completion or failure. Each final write must compare the key, running status, and attempt in the same atomic operation. A lost claim raises `Heiner\AgentGraph\Exceptions\TaskClaimLostException`.

Do not add an optional ownership argument or an unconditional fallback update. Adapt every override or decorator, including the consuming plugin's bounded task store. Existing database `attempts` need no task migration. See the [upgrade guide](../../UPGRADE.md) for the separate node-execution migration and the required coordinated process restart.

## Inspection

Inspect tasks without mutating them:

```php
$tasks = AgentGraph::tasks([
    'run_id' => $runId,
    'node_id' => 'sync_crm',
], limit: 50);
```

The read API supports `run_id`, `node_id`, `checkpoint_id`, and `status` filters.
