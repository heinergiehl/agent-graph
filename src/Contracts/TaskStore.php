<?php

namespace Heiner\AgentGraph\Contracts;

use Heiner\AgentGraph\Exceptions\TaskClaimLostException;

interface TaskStore
{
    public function findByKey(string $key): ?array;

    public function list(array $filters = [], int $limit = 50): array;

    /**
     * Atomically return a completed task or claim an available attempt.
     *
     * Running claims must include a monotonically increasing attempts value.
     * Reject different input and an existing unexpired running lease.
     */
    public function start(string $key, string $inputHash, array $input, array $context = []): array;

    /**
     * Complete only the matching running attempt.
     *
     * @throws TaskClaimLostException when the attempt is no longer owned
     */
    public function complete(string $key, int $attempt, mixed $result): array;

    /**
     * Fail only the matching running attempt.
     *
     * @throws TaskClaimLostException when the attempt is no longer owned
     */
    public function fail(string $key, int $attempt, string $message, array $meta = []): array;
}
