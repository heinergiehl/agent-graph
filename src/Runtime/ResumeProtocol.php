<?php

namespace Heiner\AgentGraph\Runtime;

use Heiner\AgentGraph\Contracts\CheckpointStore;
use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Contracts\NodeExecutionStore;
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
        private CheckpointStore $checkpoints,
        private NodeExecutionStore $executions,
    ) {}

    /** Validate accepted authority before scheduling, claiming or committing its continuation. */
    public function assertRecoveryBindings(array $run, array $graphs, array $ancestors = []): void
    {
        if (in_array($run['public_id'], $ancestors, true)) {
            throw new RuntimeException('Invalid subgraph recovery ancestry.');
        }

        if (($run['status'] ?? null) !== RunStatus::RUNNING || $this->interrupts->pendingForRun($run['public_id']) !== null) {
            return;
        }

        $checkpoint = $this->checkpoints->latestForRun($run['public_id']);
        $executions = $this->executions->listForRunStep($run['public_id'], (int) ($checkpoint['step'] ?? 0) + 1);
        $pending = data_get($run, 'meta.runtime.recovery.pending_resume');

        if ($pending !== null) {
            if (! is_array($pending) || $checkpoint === null || $executions !== []
                || ! in_array($pending['kind'] ?? null, ['resume', 'state_edit'], true)
                || ($pending['source_checkpoint_id'] ?? null) !== $checkpoint['checkpoint_id']
                || ($pending['step'] ?? null) !== (int) $checkpoint['step']
                || ! is_array($pending['resume_payload'] ?? null)
                || ! is_string($pending['resume_payload_hash'] ?? null)
                || ! hash_equals($pending['resume_payload_hash'], $this->resumePayloadHash($pending['resume_payload']))
                || ($pending['schedule'] ?? null) !== $this->scheduler->serialize($this->scheduler->fromCheckpoint($checkpoint))) {
                throw new RuntimeException('Accepted resume has inconsistent recovery metadata.');
            }

            $interrupt = $this->assertAcceptedResponse($run, $checkpoint, $pending, $graphs, $pending['kind']);
            $this->assertSubgraphResumeBinding($run, $interrupt, $pending['resume_payload'], $graphs, $ancestors);

            return;
        }

        foreach ($executions as $execution) {
            if (($execution['interrupt_id'] ?? null) === null && ($execution['resume_payload'] ?? null) === null) {
                continue;
            }

            $interrupt = $this->assertAcceptedResponse($run, $checkpoint, $execution, $graphs);
            $schedule = $this->scheduler->serialize($this->resumeSchedule($checkpoint, $interrupt));
            $state = array_merge($checkpoint['state'], $execution['resume_payload']);

            if (count($executions) !== 1
                || ($execution['run_id'] ?? null) !== $run['public_id']
                || ($execution['checkpoint_id'] ?? null) !== $checkpoint['checkpoint_id']
                || (int) ($execution['step'] ?? -1) !== (int) $checkpoint['step'] + 1
                || (int) ($execution['schedule_index'] ?? -1) !== 0
                || ($execution['node_id'] ?? null) !== $interrupt['node_id']
                || (in_array($execution['status'] ?? null, ['pending', 'running'], true) && data_get($execution, 'meta.schedule') !== $schedule[0])
                || ($execution['base_state'] ?? null) !== $state
                || ($execution['node_state'] ?? null) !== array_merge($state, $schedule[0]['input'])) {
                throw new RuntimeException('Accepted resume receipt does not match its checkpoint continuation.');
            }

            $this->assertSubgraphResumeBinding($run, $interrupt, $execution['resume_payload'], $graphs, $ancestors,
                continuationFinished: in_array($execution['status'] ?? null, ['completed', 'interrupted', 'failed'], true));
        }
    }

    private function assertAcceptedResponse(array $run, ?array $checkpoint, array $proof, array $graphs, ?string $kind = null): array
    {
        $interruptId = $proof['interrupt_id'] ?? null;
        $interrupt = is_string($interruptId) ? $this->interrupts->find($interruptId) : null;
        $payload = $proof['resume_payload'] ?? null;

        $this->assertCheckpointBinding($run, $checkpoint, $graphs);

        if ($interrupt === null || ! is_array($payload)
            || ($interrupt['interrupt_id'] ?? null) !== $interruptId
            || ($interrupt['status'] ?? null) !== 'resolved'
            || ($interrupt['run_id'] ?? null) !== $run['public_id']
            || ($interrupt['checkpoint_id'] ?? null) !== $checkpoint['checkpoint_id']
            || data_get($interrupt, 'response.interrupt_id') !== $interruptId) {
            throw new RuntimeException('Accepted resume is not bound to its resolved interrupt and checkpoint.');
        }

        $this->resumeSchedule($checkpoint, $interrupt);
        $wait = data_get($checkpoint, 'meta.runtime.wait');
        if (is_array($wait) && (($wait['node_id'] ?? null) !== $interrupt['node_id'] || ($wait['type'] ?? null) !== $interrupt['type'])) {
            throw new RuntimeException('Accepted resume no longer matches the checkpoint wait.');
        }

        $response = $interrupt['response'];
        unset($response['interrupt_id']);
        $matches = $kind !== 'state_edit' && $response === $payload;
        if (! $matches && $kind !== 'resume' && $interrupt['type'] === 'state_edit') {
            $matches = $response === ['state' => $payload];
        }
        if (! $matches) {
            throw new RuntimeException('Accepted resume response changed before recovery.');
        }

        return $interrupt;
    }

    private function assertCheckpointBinding(array $run, ?array $checkpoint, array $graphs): void
    {
        $graph = $graphs[$run['graph_key']] ?? null;
        if ($checkpoint === null || $graph === null
            || $graph->key() !== $run['graph_key'] || $graph->version() !== $run['graph_version']
            || ($checkpoint['checkpoint_id'] ?? null) !== ($run['current_checkpoint_id'] ?? null)
            || ($checkpoint['run_id'] ?? null) !== $run['public_id']
            || ($checkpoint['thread_id'] ?? null) !== $run['thread_id']
            || ($checkpoint['graph_key'] ?? null) !== $run['graph_key']
            || ($checkpoint['graph_version'] ?? null) !== $run['graph_version']) {
            throw new RuntimeException('Accepted resume has an invalid run, checkpoint or graph binding.');
        }
    }

    public function matchesAcceptedResume(array $run, string $interruptId, array $payload): bool
    {
        $accepted = $this->acceptedResumePayload($run, $interruptId);

        return $accepted !== null && hash_equals($this->resumePayloadHash($accepted), $this->resumePayloadHash($payload));
    }

    private function acceptedResumePayload(array $run, string $interruptId): ?array
    {
        $pending = data_get($run, 'meta.runtime.recovery.pending_resume');
        if (is_array($pending)) {
            return ($pending['interrupt_id'] ?? null) === $interruptId && is_array($pending['resume_payload'] ?? null)
                ? $pending['resume_payload'] : null;
        }

        $checkpoint = $this->checkpoints->latestForRun($run['public_id']);
        foreach ($this->executions->listForRunStep($run['public_id'], (int) ($checkpoint['step'] ?? 0) + 1) as $execution) {
            if (($execution['checkpoint_id'] ?? null) === ($checkpoint['checkpoint_id'] ?? null)
                && ($execution['interrupt_id'] ?? null) === $interruptId && is_array($execution['resume_payload'] ?? null)) {
                return $execution['resume_payload'];
            }
        }

        return null;
    }

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
            || ($checkpoint['next_nodes'] ?? null) !== [$interrupt['node_id']]
            || $this->scheduler->nodeIds($schedule) !== [$interrupt['node_id']]) {
            throw new InvalidArgumentException('The pending interrupt does not match the checkpoint continuation; reconciliation is required.');
        }

        return $schedule;
    }

    public function assertSubgraphResumeBinding(array $run, ?array $interrupt, array $payload, array $graphs, array $ancestors = [], bool $continuationFinished = false): void
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

        $this->assertOriginalChildInterrupt($child, $binding);

        $childInterrupt = $this->interrupts->pendingForRun($childRunId);
        $accepted = $this->acceptedResumePayload($child, $childInterruptId) ?? [];
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
        if ($childGraph->key() !== $child['graph_key'] || (string) ($child['graph_version'] ?? '') !== $childGraph->version()) {
            throw new RuntimeException("Child run graph version [{$child['graph_version']}] does not match registered graph version [{$childGraph->version()}].");
        }

        if (RunStatus::isTerminal($child['status'] ?? null)) {
            $this->assertHistoricalChildResponse($child, $binding, $payload);

            return;
        }

        (new StateSchemaValidator)->assertPatch($childGraph->schema(), $childPayload, false);

        if (($childInterrupt['interrupt_id'] ?? null) === $childInterruptId) {
            $this->assertMatchingPendingInterrupt($childRunId, $childInterruptId, $childInterrupt);
            $checkpoint = $this->checkpoints->latestForRun($childRunId);
            $this->assertCheckpointBinding($child, $checkpoint, $graphs);
            $this->resumeSchedule($checkpoint, $childInterrupt);
            $this->assertSubgraphResumeBinding($child, $childInterrupt, $childPayload, $graphs, [...$ancestors, $run['public_id']]);

            return;
        }

        // A finished parent receipt can legitimately contain the child's next wait.
        // It authorizes committing that result, never invoking the child again.
        if ($continuationFinished && $childInterrupt !== null) {
            $this->assertHistoricalChildResponse($child, $binding, $payload);

            return;
        }

        if ($childInterrupt === null && ($child['status'] ?? null) === 'running') {
            // Preserve the field order used by existing accepted payload hashes.
            $childPayload = array_replace(array_intersect_key($accepted, $childPayload), $childPayload);

            if ($this->matchesAcceptedResume($child, $childInterruptId, $childPayload)) {
                $this->assertRecoveryBindings($child, $graphs, [...$ancestors, $run['public_id']]);

                return;
            }

            throw new InvalidArgumentException("Child resume payload does not match the accepted response for child run [{$childRunId}].");
        }

        throw new InvalidArgumentException("Child interrupt [{$childInterruptId}] is no longer the pending interrupt for child run [{$childRunId}].");
    }

    /** Child progress may change its current checkpoint, never the original wait or accepted answer. */
    private function assertHistoricalChildResponse(array $child, array $binding, array $payload): void
    {
        $interruptId = $binding['child_interrupt_id'];
        $resolved = $this->interrupts->find($interruptId);
        $checkpoint = is_string($resolved['checkpoint_id'] ?? null) ? $this->checkpoints->find($resolved['checkpoint_id']) : null;
        $response = $resolved['response'] ?? null;

        if ($resolved === null || $checkpoint === null || ! is_array($response)
            || ($resolved['interrupt_id'] ?? null) !== $interruptId
            || ($resolved['run_id'] ?? null) !== $child['public_id']
            || ($resolved['status'] ?? null) !== 'resolved'
            || ($response['interrupt_id'] ?? null) !== $interruptId
            || ($checkpoint['run_id'] ?? null) !== $child['public_id']
            || ($checkpoint['thread_id'] ?? null) !== $child['thread_id']
            || ($checkpoint['graph_key'] ?? null) !== $child['graph_key']
            || ($checkpoint['graph_version'] ?? null) !== $child['graph_version']) {
            throw new RuntimeException('Accepted child resume lost its historical interrupt or checkpoint binding.');
        }

        $this->resumeSchedule($checkpoint, $resolved);

        if (($child['status'] ?? null) === RunStatus::CANCELLED && ($response['type'] ?? null) === 'cancelled') {
            return;
        }

        if (($resolved['type'] ?? null) === 'subgraph'
            && (($response['child_run_id'] ?? null) !== data_get($resolved, 'payload.child_run_id')
                || ($response['child_interrupt_id'] ?? null) !== data_get($resolved, 'payload.child_interrupt_id'))) {
            throw new RuntimeException('Accepted child response changed its nested subgraph binding.');
        }

        unset($payload['child_run_id'], $payload['child_interrupt_id']);
        unset($response['interrupt_id'], $response['child_run_id'], $response['child_interrupt_id']);
        if ($response !== $payload && ! (($resolved['type'] ?? null) === 'state_edit' && $response === ['state' => $payload])) {
            throw new RuntimeException('Accepted child response differs from its parent authorization.');
        }
    }

    private function assertOriginalChildInterrupt(array $child, array $binding): void
    {
        $interrupt = $this->interrupts->find($binding['child_interrupt_id']);
        if ($interrupt === null || ($interrupt['run_id'] ?? null) !== $child['public_id']
            || ($interrupt['interrupt_id'] ?? null) !== $binding['child_interrupt_id']) {
            throw new RuntimeException('Accepted parent wait lost its original child interrupt.');
        }

        if (is_array($binding['child_interrupt'] ?? null)) {
            foreach (['interrupt_id', 'run_id', 'checkpoint_id', 'node_id', 'type', 'payload'] as $field) {
                if (($binding['child_interrupt'][$field] ?? null) !== ($interrupt[$field] ?? null)) {
                    throw new RuntimeException('Accepted child interrupt differs from the original parent wait.');
                }
            }
        }
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
