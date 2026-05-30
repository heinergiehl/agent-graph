<?php

namespace Heiner\AgentGraph\Runtime;

use Heiner\AgentGraph\AgentGraphManager;
use RuntimeException;

class DurableGraphSession
{
    public function __construct(
        protected AgentGraphManager $manager,
        protected string $graphKey,
        protected string $threadId,
    ) {}

    public function start(array $input = [], array $meta = [], RuntimeOptions|array $options = []): RunResult
    {
        return $this->manager->graph($this->graphKey)
            ->thread($this->threadId)
            ->input($input)
            ->meta($meta)
            ->options($options)
            ->run();
    }

    public function run(array $input = [], array $meta = [], RuntimeOptions|array $options = []): RunResult
    {
        return $this->manager->runSession($this->graphKey, $this->threadId, $input, $meta, $options);
    }

    public function resume(array $payload = [], bool $strict = false, RuntimeOptions|array $options = []): RunResult
    {
        $runId = $payload['run_id'] ?? $this->activeRun()['public_id'] ?? null;

        if (! is_string($runId) || $runId === '') {
            throw new RuntimeException("No active run found for graph [{$this->graphKey}] and thread [{$this->threadId}].");
        }

        unset($payload['run_id']);

        return $strict
            ? $this->manager->resumeStrict($runId, $payload, options: $options)
            : $this->manager->resume($runId, $payload, options: $options);
    }

    public function cancel(array $meta = []): RunResult
    {
        $active = $this->activeRun();

        if ($active === null) {
            throw new RuntimeException("No active run found for graph [{$this->graphKey}] and thread [{$this->threadId}].");
        }

        return $this->manager->cancel($active['public_id'], $meta);
    }

    public function status(): array
    {
        $active = $this->activeRun();

        if ($active === null) {
            return [
                'status' => 'idle',
                'run_id' => null,
                'thread_id' => $this->threadId,
                'graph_key' => $this->graphKey,
                'interrupt' => null,
                'summary' => null,
            ];
        }

        $snapshot = $this->manager->inspect($active['public_id']);

        return [
            'status' => $snapshot->status(),
            'run_id' => $snapshot->runId(),
            'thread_id' => $snapshot->threadId(),
            'graph_key' => $snapshot->graphKey(),
            'interrupt' => $snapshot->interrupt(),
            'summary' => [
                'checkpoint_id' => $snapshot->checkpoint()['checkpoint_id'] ?? null,
                'state' => $snapshot->state(),
            ],
        ];
    }

    public function activeRun(): ?array
    {
        return $this->manager->latestForThreadGraph($this->threadId, $this->graphKey);
    }
}
