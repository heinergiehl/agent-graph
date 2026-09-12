<?php

namespace Heiner\AgentGraph\Runtime;

use Heiner\AgentGraph\Events\GraphEvent;

class RunEventDispatcher
{
    /**
     * @var array<int, array{run_id: ?string, listener: ?callable, collect: bool, events: array<int, RunEvent>}>
     */
    protected array $observers = [];

    public function dispatch(string $type, GraphEvent $event): void
    {
        $this->notify(fn () => event($event));

        if ($this->observers === []) {
            return;
        }

        $runEvent = RunEvent::fromGraphEvent($type, $event);
        $key = array_key_last($this->observers);

        if (! $this->matchesObserver($key, $runEvent)) {
            return;
        }

        $listener = $this->observers[$key]['listener'];

        if ($listener !== null) {
            $this->notify(fn () => $listener($runEvent));
        }

        if ($this->observers[$key]['collect']) {
            $this->observers[$key]['events'][] = $runEvent;
        }
    }

    /** Observers are diagnostics, never authorization or durable execution receipts. */
    public function notify(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            try {
                report($exception);
            } catch (\Throwable) {
                // A broken diagnostics sink cannot change a business outcome.
            }
        }
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return array{result: TResult, events: array<int, RunEvent>}
     */
    public function observe(?callable $listener, bool $collect, callable $callback, ?string $runId = null): array
    {
        if ($listener === null && ! $collect) {
            return ['result' => $callback(), 'events' => []];
        }

        $this->observers[] = [
            'run_id' => $runId,
            'listener' => $listener,
            'collect' => $collect,
            'events' => [],
        ];

        try {
            $result = $callback();
            $key = array_key_last($this->observers);

            return [
                'result' => $result,
                'events' => $this->observers[$key]['events'],
            ];
        } finally {
            array_pop($this->observers);
        }
    }

    protected function matchesObserver(int $key, RunEvent $event): bool
    {
        $runId = $this->observers[$key]['run_id'];

        if ($runId === null && $event->runId() !== null && in_array($event->type(), ['run.started', 'run.resumed'], true)) {
            $this->observers[$key]['run_id'] = $event->runId();
            $runId = $event->runId();
        }

        return $runId === null || $event->runId() === $runId;
    }
}
