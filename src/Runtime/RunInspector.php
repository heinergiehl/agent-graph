<?php

namespace Heiner\AgentGraph\Runtime;

use Heiner\AgentGraph\Contracts\CheckpointStore;
use Heiner\AgentGraph\Contracts\InterruptStore;
use Heiner\AgentGraph\Contracts\RunStore;
use Heiner\AgentGraph\Contracts\TraceStore;
use Heiner\AgentGraph\Contracts\WriteStore;
use Heiner\AgentGraph\Tracing\RedactsTracePayloads;

class RunInspector
{
    use RedactsTracePayloads;

    public function __construct(
        protected RunStore $runs,
        protected CheckpointStore $checkpoints,
        protected WriteStore $writes,
        protected InterruptStore $interrupts,
        protected TraceStore $traces,
    ) {}

    public function timeline(string $runId, bool $includeState = false, bool $includeDiff = true): ?RunTimeline
    {
        $run = $this->runs->find($runId);

        if ($run === null) {
            return null;
        }

        $checkpoints = $this->checkpoints->listForRun($runId);
        $writes = $this->groupBy($this->writes->listForRun($runId), 'checkpoint_id');
        $interrupts = $this->groupBy($this->interrupts->listForRun($runId), 'checkpoint_id');
        $traces = $this->traces->listForRun($runId);
        $steps = [];
        $previousState = is_array($run['input'] ?? null) ? $run['input'] : [];
        $latestCheckpointId = $checkpoints === [] ? null : (string) end($checkpoints)['checkpoint_id'];

        foreach ($checkpoints as $checkpoint) {
            $checkpointId = (string) $checkpoint['checkpoint_id'];
            $checkpointWrites = $writes[$checkpointId] ?? [];
            $checkpointInterrupt = $this->first($interrupts[$checkpointId] ?? []);
            $stateAfter = is_array($checkpoint['state'] ?? null) ? $checkpoint['state'] : [];
            $isLatest = $latestCheckpointId === $checkpointId;

            $steps[] = new RunTimelineStep(
                step: (int) $checkpoint['step'],
                nodeId: $this->nodeIdForCheckpoint($checkpoint, $checkpointWrites, $checkpointInterrupt),
                status: $this->statusForCheckpoint($run, $checkpoint, $checkpointInterrupt, $isLatest),
                checkpointId: $checkpointId,
                previousCheckpointId: is_string($checkpoint['parent_checkpoint_id'] ?? null) ? $checkpoint['parent_checkpoint_id'] : null,
                writes: $this->redactWrites($checkpointWrites),
                interrupt: $checkpointInterrupt !== null ? $this->redactPayload($checkpointInterrupt) : null,
                error: $isLatest && ($run['status'] ?? null) === 'failed' ? $this->redactPayload($run['error'] ?? []) : null,
                meta: $this->redactPayload(is_array($checkpoint['meta'] ?? null) ? $checkpoint['meta'] : []),
                stateBefore: $includeState ? $this->redactPayload($previousState) : null,
                stateAfter: $includeState ? $this->redactPayload($stateAfter) : null,
                stateDiff: $includeDiff ? $this->stateDiff($previousState, $stateAfter) : null,
            );

            $previousState = $stateAfter;
        }

        if (($run['status'] ?? null) === 'failed' && ! $this->timelineAlreadyFailed($steps)) {
            $failedTrace = $this->lastFailedTrace($traces);

            $steps[] = new RunTimelineStep(
                step: count($steps) + 1,
                nodeId: is_string(data_get($failedTrace, 'payload.node')) ? data_get($failedTrace, 'payload.node') : null,
                status: 'failed',
                checkpointId: null,
                previousCheckpointId: $latestCheckpointId,
                writes: [],
                interrupt: null,
                error: $this->redactPayload([
                    'message' => data_get($failedTrace, 'payload.message', data_get($run, 'error.message')),
                ]),
                meta: $this->redactPayload(is_array($failedTrace['meta'] ?? null) ? $failedTrace['meta'] : []),
                stateBefore: $includeState ? $this->redactPayload($previousState) : null,
                stateAfter: null,
                stateDiff: $includeDiff ? StateDiff::empty() : null,
            );
        }

        return new RunTimeline($run, $steps);
    }

    protected function stateDiff(array $before, array $after): StateDiff
    {
        $added = [];
        $changed = [];
        $removed = [];

        foreach (array_keys($after) as $key) {
            if (! array_key_exists($key, $before)) {
                $added[$key] = $after[$key];
            } elseif ($before[$key] !== $after[$key]) {
                $changed[$key] = [
                    'before' => $before[$key],
                    'after' => $after[$key],
                ];
            }
        }

        foreach (array_keys($before) as $key) {
            if (! array_key_exists($key, $after)) {
                $removed[$key] = $before[$key];
            }
        }

        return new StateDiff(
            added: $this->redactPayload($added),
            changed: $this->redactPayload($changed),
            removed: $this->redactPayload($removed),
        );
    }

    protected function statusForCheckpoint(array $run, array $checkpoint, ?array $interrupt, bool $isLatest): string
    {
        $nodeStatus = data_get($checkpoint, 'meta.node.status');

        if (is_string($nodeStatus) && $nodeStatus !== '') {
            return $nodeStatus;
        }

        if ($interrupt !== null) {
            return ($interrupt['type'] ?? null) === 'delay' ? 'delayed' : 'interrupted';
        }

        if ($isLatest && ($run['status'] ?? null) === 'failed') {
            return 'failed';
        }

        return 'completed';
    }

    protected function nodeIdForCheckpoint(array $checkpoint, array $writes, ?array $interrupt): ?string
    {
        $completed = is_array($checkpoint['completed_nodes'] ?? null) ? $checkpoint['completed_nodes'] : [];
        $nodeId = $completed[0] ?? null;

        if (is_string($nodeId) && $nodeId !== '') {
            return $nodeId;
        }

        $writeNode = data_get($writes, '0.node_id');

        if (is_string($writeNode) && $writeNode !== '') {
            return $writeNode;
        }

        $interruptNode = $interrupt['node_id'] ?? null;

        return is_string($interruptNode) && $interruptNode !== '' ? $interruptNode : null;
    }

    protected function redactWrites(array $writes): array
    {
        return array_map(function (array $write): array {
            $channel = is_string($write['channel'] ?? null) ? $write['channel'] : null;

            if ($channel !== null && $this->isRedactedKey($channel)) {
                $write['value'] = '[redacted]';
            } elseif (array_key_exists('value', $write)) {
                $write['value'] = $this->redactPayload(['value' => $write['value']])['value'];
            }

            if (is_array($write['meta'] ?? null)) {
                $write['meta'] = $this->redactPayload($write['meta']);
            }

            return $write;
        }, $writes);
    }

    protected function isRedactedKey(string $key): bool
    {
        return in_array(strtolower($key), array_map('strtolower', config('agent-graph.tracing.redact_keys', [])), true);
    }

    protected function lastFailedTrace(array $traces): ?array
    {
        foreach (array_reverse($traces) as $trace) {
            if (($trace['event'] ?? null) === 'node.failed') {
                return $trace;
            }
        }

        return null;
    }

    /**
     * @param  array<int, RunTimelineStep>  $steps
     */
    protected function timelineAlreadyFailed(array $steps): bool
    {
        foreach ($steps as $step) {
            if ($step->status() === 'failed') {
                return true;
            }
        }

        return false;
    }

    protected function first(array $items): ?array
    {
        return $items === [] ? null : $items[array_key_first($items)];
    }

    protected function groupBy(array $items, string $key): array
    {
        $groups = [];

        foreach ($items as $item) {
            $value = $item[$key] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            $groups[$value] ??= [];
            $groups[$value][] = $item;
        }

        return $groups;
    }
}
