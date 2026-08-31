<?php

namespace Heiner\AgentGraph\Contracts;

use DateTimeInterface;

interface DelayScheduler
{
    /**
     * Schedule a continuation for the persisted delay interrupt at its original
     * absolute due time. Recovery may repeat this call for the same run and
     * interrupt, including when the due time is already in the past.
     *
     * Implementations must use a durable asynchronous transport that honors the
     * due time, tolerate repeated delivery and preserve interrupt identity.
     * Delivery must use the runtime's guarded resume path. Recovery invokes
     * scheduling under the run lock, so implementations must not resolve the
     * interrupt or resume inline. Laravel sync/deferred queues do not meet
     * these transport requirements; the runtime does not enforce the backend.
     */
    public function schedule(string $runId, array $payload, DateTimeInterface $resumeAt): void;
}
