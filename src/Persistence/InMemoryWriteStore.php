<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Contracts\WriteStore;

class InMemoryWriteStore implements WriteStore
{
    protected array $writes = [];

    public function createMany(string $runId, string $checkpointId, string $nodeId, array $writes, array $meta = []): void
    {
        foreach ($writes as $channel => $value) {
            $this->writes[] = [
                'id' => count($this->writes) + 1,
                'run_id' => $runId,
                'checkpoint_id' => $checkpointId,
                'node_id' => $nodeId,
                'channel' => $channel,
                'key' => $channel,
                'value' => $value,
                'reducer' => null,
                'meta' => $meta,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
    }

    public function listForRun(string $runId): array
    {
        return array_values(array_filter($this->writes, fn (array $write): bool => $write['run_id'] === $runId));
    }
}
