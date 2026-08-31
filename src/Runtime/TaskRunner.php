<?php

namespace Heiner\AgentGraph\Runtime;

use Closure;
use Heiner\AgentGraph\Contracts\TaskStore;
use Heiner\AgentGraph\Events\GraphTaskCompleted;
use Heiner\AgentGraph\Events\GraphTaskFailed;
use Heiner\AgentGraph\Events\GraphTaskStarted;
use Heiner\AgentGraph\Exceptions\SerializationException;
use JsonException;
use RuntimeException;

class TaskRunner
{
    public function __construct(
        protected TaskStore $tasks,
        protected string $runId,
        protected string $nodeId,
        protected ?string $checkpointId = null,
    ) {}

    public function once(string $key, array $input, Closure $handler): mixed
    {
        try {
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new SerializationException('AgentGraph task input is not JSON serializable: '.$exception->getMessage(), previous: $exception);
        }

        $task = $this->tasks->start($key, $hash, $input, [
            'run_id' => $this->runId,
            'node_id' => $this->nodeId,
            'checkpoint_id' => $this->checkpointId,
        ]);

        if ($task['status'] === 'completed') {
            return $task['result'];
        }

        $attempt = (int) ($task['attempts'] ?? 0);

        if ($task['status'] !== 'running' || $attempt < 1) {
            throw new RuntimeException("Task key [{$key}] did not return an owned running attempt.");
        }

        try {
            event(new GraphTaskStarted($this->runId, nodeId: $this->nodeId, payload: ['task_key' => $key, 'input' => $input]));
            $result = $handler();
        } catch (\Throwable $exception) {
            $this->tasks->fail($key, $attempt, $exception->getMessage());
            event(new GraphTaskFailed($this->runId, nodeId: $this->nodeId, payload: ['task_key' => $key, 'message' => $exception->getMessage()]));

            throw $exception;
        }

        $this->tasks->complete($key, $attempt, $result);
        event(new GraphTaskCompleted($this->runId, nodeId: $this->nodeId, payload: ['task_key' => $key, 'result' => $result]));

        return $result;
    }
}
