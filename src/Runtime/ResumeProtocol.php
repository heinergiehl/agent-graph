<?php

namespace Heiner\AgentGraph\Runtime;

use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Contracts\RunStore;
use Heiner\AgentGraph\Graph\InterruptContract;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\State\StateSchemaValidator;
use InvalidArgumentException;
use RuntimeException;

/** @internal Validates wait/response bindings and their durable recovery proof; never writes state. */
final class ResumeProtocol
{
    public function __construct(
        private RunStore $runs,
        private InterruptStore $interrupts,
        private RuntimeScheduler $scheduler,
    ) {}

    public function assertCheckpointContinuationIsSafe(array $run, ?array $checkpoint, array $executions): void
    {
        if ($checkpoint === null || is_array(data_get($run, 'meta.runtime.recovery.pending_resume'))) {
            return;
        }

        $schedule = $this->scheduler->fromCheckpoint($checkpoint);
        $nextNodes = array_values(array_filter(
            $checkpoint['next_nodes'] ?? [],
            fn (string $nodeId): bool => $nodeId !== StateGraph::END,
        ));
        $inconsistent = $this->scheduler->nodeIds($schedule) !== $nextNodes;
        $waiting = is_array(data_get($checkpoint, 'meta.runtime.wait'));

        if (($inconsistent || $waiting) && ! $this->hasAcceptedQueuedResume($checkpoint, $executions)) {
            if ($inconsistent) {
                throw new RuntimeException("Run [{$run['public_id']}] has an inconsistent recovery schedule; reconcile the checkpoint before recovery.");
            }

            throw new RuntimeException("Run [{$run['public_id']}] has a wait checkpoint without a pending interrupt or accepted resume; reconciliation is required.");
        }
    }

    private function hasAcceptedQueuedResume(array $checkpoint, array $executions): bool
    {
        if ($executions === [] || array_column($executions, 'node_id') !== ($checkpoint['next_nodes'] ?? [])) {
            return false;
        }

        foreach ($executions as $execution) {
            $interruptId = $execution['interrupt_id'] ?? null;

            if (! is_string($interruptId) || $interruptId === ''
                || ($execution['checkpoint_id'] ?? null) !== $checkpoint['checkpoint_id']
                || ! is_array($execution['resume_payload'] ?? null)) {
                return false;
            }

            $interrupt = $this->interrupts->find($interruptId);

            if (($interrupt['status'] ?? null) !== 'resolved'
                || ($interrupt['run_id'] ?? null) !== $checkpoint['run_id']
                || ($interrupt['checkpoint_id'] ?? null) !== $checkpoint['checkpoint_id']
                || ($interrupt['node_id'] ?? null) !== $execution['node_id']
                || ! is_array($interrupt['response'] ?? null)) {
                return false;
            }

            $response = $interrupt['response'];
            unset($response['interrupt_id']);

            $expectedHash = $this->resumePayloadHash($execution['resume_payload']);
            $matches = hash_equals($this->resumePayloadHash($response), $expectedHash);

            if (! $matches && ($interrupt['type'] ?? null) === 'state_edit' && is_array($response['state'] ?? null)) {
                $matches = hash_equals($this->resumePayloadHash($response['state']), $expectedHash);
            }

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    public function matchesPendingResumeRecovery(array $run, string $interruptId, array $resumePayload): bool
    {
        $pending = data_get($run, 'meta.runtime.recovery.pending_resume');

        return is_array($pending)
            && hash_equals((string) ($pending['interrupt_id'] ?? ''), $interruptId)
            && hash_equals(
                (string) ($pending['resume_payload_hash'] ?? ''),
                $this->resumePayloadHash($resumePayload),
            );
    }

    public function withPendingResumeRecovery(
        array $meta,
        string $kind,
        string $interruptId,
        array $checkpoint,
        array $resumePayload,
        array $schedule,
    ): array {
        data_set($meta, 'runtime.recovery.pending_resume', [
            'kind' => $kind,
            'interrupt_id' => $interruptId,
            'source_checkpoint_id' => $checkpoint['checkpoint_id'] ?? null,
            'step' => (int) ($checkpoint['step'] ?? 0),
            'resume_payload' => $resumePayload,
            'resume_payload_hash' => $this->resumePayloadHash($resumePayload),
            'schedule' => $this->scheduler->serialize($schedule),
            'accepted_at' => now()->toISOString(),
        ]);

        return $meta;
    }

    private function resumePayloadHash(array $resumePayload): string
    {
        return hash('sha256', json_encode(
            $resumePayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    public function withoutPendingResumeRecovery(array $meta): array
    {
        if (is_array($meta['runtime']['recovery'] ?? null)) {
            unset($meta['runtime']['recovery']['pending_resume']);

            if ($meta['runtime']['recovery'] === []) {
                unset($meta['runtime']['recovery']);
            }
        }

        if (is_array($meta['runtime'] ?? null) && $meta['runtime'] === []) {
            unset($meta['runtime']);
        }

        return $meta;
    }

    public function assertMatchingPendingInterrupt(string $runId, string $interruptId, ?array $interrupt): void
    {
        if ($interrupt === null) {
            throw new InvalidArgumentException("Run [{$runId}] has no pending interrupt.");
        }

        if (($interrupt['interrupt_id'] ?? null) !== $interruptId) {
            throw new InvalidArgumentException("Interrupt [{$interruptId}] does not match the pending interrupt for run [{$runId}].");
        }

        if (($interrupt['expires_at'] ?? null) !== null && now()->greaterThanOrEqualTo($interrupt['expires_at'])) {
            throw new InvalidArgumentException("Interrupt [{$interruptId}] has expired and cannot be resumed.");
        }
    }

    public function resumeSchedule(array $checkpoint, array $interrupt): array
    {
        $schedule = $this->scheduler->fromCheckpoint($checkpoint);

        if (($interrupt['checkpoint_id'] ?? null) !== $checkpoint['checkpoint_id']
            || ($interrupt['run_id'] ?? null) !== $checkpoint['run_id']
            || $this->scheduler->nodeIds($schedule) !== [$interrupt['node_id']]) {
            throw new InvalidArgumentException('The pending interrupt does not match the checkpoint continuation; reconciliation is required.');
        }

        return $schedule;
    }

    public function assertSubgraphResumeBinding(array $run, ?array $interrupt, array $payload, array $graphs, array $ancestors = []): void
    {
        if (($interrupt['type'] ?? null) !== 'subgraph') {
            if (array_key_exists('child_run_id', $payload) || array_key_exists('child_interrupt_id', $payload)) {
                throw new InvalidArgumentException('Child run identities are only valid for a pending subgraph interrupt.');
            }

            return;
        }

        $binding = is_array($interrupt['payload'] ?? null) ? $interrupt['payload'] : [];
        $childRunId = $binding['child_run_id'] ?? null;
        $childInterruptId = $binding['child_interrupt_id'] ?? null;

        if (! is_string($childRunId) || $childRunId === ''
            || ! is_string($childInterruptId) || $childInterruptId === ''
            || ($payload['child_run_id'] ?? null) !== $childRunId
            || ($payload['child_interrupt_id'] ?? null) !== $childInterruptId) {
            throw new InvalidArgumentException('Child run identities must match the pending parent subgraph interrupt.');
        }

        $child = $this->runs->find($childRunId);

        if ($child === null
            || $childRunId === $run['public_id']
            || in_array($childRunId, $ancestors, true)
            || data_get($child, 'meta.parent.relationship') !== 'subgraph'
            || data_get($child, 'meta.parent.run_id') !== $run['public_id']
            || data_get($child, 'meta.parent.node_id') !== ($interrupt['node_id'] ?? null)) {
            throw new InvalidArgumentException("Child run [{$childRunId}] is not bound to the pending parent node.");
        }

        if (RunStatus::isTerminal($child['status'] ?? null)) {
            return;
        }

        $childInterrupt = $this->interrupts->pendingForRun($childRunId);
        $accepted = data_get($child, 'meta.runtime.recovery.pending_resume.resume_payload');
        $accepted = is_array($accepted) ? $accepted : [];
        $nested = ($childInterrupt['type'] ?? null) === 'subgraph'
            ? ($childInterrupt['payload'] ?? [])
            : ($childInterrupt === null ? $accepted : []);
        $childPayload = $payload;
        unset($childPayload['child_run_id'], $childPayload['child_interrupt_id']);

        foreach (['child_run_id', 'child_interrupt_id'] as $key) {
            if (is_string($nested[$key] ?? null)) {
                $childPayload[$key] = $nested[$key];
            }
        }

        $childGraph = $graphs[$child['graph_key']] ?? throw new RuntimeException("Graph [{$child['graph_key']}] is not defined.");
        if ((string) ($child['graph_version'] ?? '') !== $childGraph->version()) {
            throw new RuntimeException("Child run graph version [{$child['graph_version']}] does not match registered graph version [{$childGraph->version()}].");
        }
        (new StateSchemaValidator)->assertPatch($childGraph->schema(), $childPayload, false);

        if (($childInterrupt['interrupt_id'] ?? null) === $childInterruptId) {
            $this->assertSubgraphResumeBinding($child, $childInterrupt, $childPayload, $graphs, [...$ancestors, $run['public_id']]);

            return;
        }

        if ($childInterrupt === null && ($child['status'] ?? null) === 'running'
            && data_get($child, 'meta.runtime.recovery.pending_resume.interrupt_id') === $childInterruptId) {
            // Preserve the field order used by existing accepted payload hashes.
            $childPayload = array_replace(array_intersect_key($accepted, $childPayload), $childPayload);

            if ($this->matchesPendingResumeRecovery($child, $childInterruptId, $childPayload)) {
                return;
            }

            throw new InvalidArgumentException("Child resume payload does not match the accepted response for child run [{$childRunId}].");
        }

        throw new InvalidArgumentException("Child interrupt [{$childInterruptId}] is no longer the pending interrupt for child run [{$childRunId}].");
    }

    public function assertInterruptContractResponse(?array $interrupt, array $response, bool $validateInterruptContract): void
    {
        if (! $validateInterruptContract || $interrupt === null || ! is_array($interrupt['payload'] ?? null)) {
            return;
        }

        $payload = $interrupt['payload'];

        if (! InterruptContract::isContractPayload($payload)) {
            return;
        }

        InterruptContract::fromArray($payload, (string) ($interrupt['type'] ?? 'input'))
            ->assertResponse($response);
    }
}
