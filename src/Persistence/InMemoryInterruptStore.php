<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\InterruptStore;
use RuntimeException;

class InMemoryInterruptStore implements InterruptStore
{
    protected array $interrupts = [];

    public function create(array $interrupt): array
    {
        $interrupt = array_merge([
            'id' => count($this->interrupts) + 1,
            'interrupt_id' => 'int_'.str()->ulid(),
            'status' => 'pending',
            'response' => null,
            'resolved_by' => null,
            'resolved_at' => null,
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $interrupt);

        $this->interrupts[$interrupt['interrupt_id']] = $interrupt;

        return $interrupt;
    }

    public function find(string $interruptId): ?array
    {
        return $this->interrupts[$interruptId] ?? null;
    }

    public function listForRun(string $runId): array
    {
        return array_values(array_filter($this->interrupts, fn (array $interrupt): bool => $interrupt['run_id'] === $runId));
    }

    public function pendingForRun(string $runId): ?array
    {
        foreach (array_reverse($this->interrupts) as $interrupt) {
            if ($interrupt['run_id'] === $runId && $interrupt['status'] === 'pending') {
                return $interrupt;
            }
        }

        return null;
    }

    public function resolve(string $interruptId, array $response, ?string $resolvedBy = null): array
    {
        $this->interrupts[$interruptId]['status'] = 'resolved';
        $this->interrupts[$interruptId]['response'] = $response;
        $this->interrupts[$interruptId]['resolved_by'] = $resolvedBy;
        $this->interrupts[$interruptId]['resolved_at'] = now();
        $this->interrupts[$interruptId]['updated_at'] = now();

        return $this->interrupts[$interruptId];
    }

    public function resolvePending(string $interruptId, string $runId, array $response, ?string $resolvedBy = null): array
    {
        $interrupt = $this->interrupts[$interruptId] ?? null;

        if ($interrupt === null || $interrupt['run_id'] !== $runId || $interrupt['status'] !== 'pending') {
            throw new RuntimeException("Interrupt is no longer pending for run [{$runId}] and interrupt [{$interruptId}].");
        }

        return $this->resolve($interruptId, $response, $resolvedBy);
    }

    public function expirePending(mixed $now = null): int
    {
        $now ??= now();
        $expired = 0;

        foreach ($this->interrupts as $id => $interrupt) {
            if ($interrupt['status'] !== 'pending' || ($interrupt['expires_at'] ?? null) === null) {
                continue;
            }

            if ($now->greaterThanOrEqualTo($interrupt['expires_at'])) {
                $this->interrupts[$id]['status'] = 'expired';
                $this->interrupts[$id]['updated_at'] = now();
                $expired++;
            }
        }

        return $expired;
    }
}
