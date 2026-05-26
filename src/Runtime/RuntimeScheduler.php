<?php

namespace Heiner\AgentGraph\Runtime;

use Heiner\AgentGraph\Graph\StateGraph;
use RuntimeException;

class RuntimeScheduler
{
    /**
     * @param  array<int, mixed>  $items
     * @return array<int, Send>
     */
    public function normalize(array $items): array
    {
        $schedule = [];

        foreach ($items as $item) {
            $send = Send::from($item);

            if ($send->node() === StateGraph::END) {
                continue;
            }

            $schedule[] = $send;
        }

        return $this->deduplicate($schedule);
    }

    /**
     * @return array<int, Send>
     */
    public function fromCheckpoint(array $checkpoint): array
    {
        $stored = data_get($checkpoint, 'meta.runtime.schedule.next');

        if (is_array($stored) && $stored !== []) {
            return $this->normalize($stored);
        }

        return $this->normalize(is_array($checkpoint['next_nodes'] ?? null) ? $checkpoint['next_nodes'] : []);
    }

    /**
     * @param  array<int, Send>  $schedule
     * @return array<int, string>
     */
    public function nodeIds(array $schedule): array
    {
        return array_map(fn (Send $send): string => $send->node(), $schedule);
    }

    /**
     * @param  array<int, Send>  $schedule
     * @return array<int, array{node: string, input: array, meta: array}>
     */
    public function serialize(array $schedule): array
    {
        return array_map(fn (Send $send): array => $send->toArray(), $schedule);
    }

    /**
     * @param  array<int, Send>  $schedule
     */
    public function assertWithinLimit(array $schedule): void
    {
        $max = (int) config('agent-graph.max_parallel_nodes', 50);

        if ($max > 0 && count($schedule) > $max) {
            throw new RuntimeException('Superstep scheduled ['.count($schedule)."] nodes, exceeding max_parallel_nodes [{$max}].");
        }
    }

    /**
     * @param  array<int, Send>  $schedule
     * @return array<int, Send>
     */
    protected function deduplicate(array $schedule): array
    {
        $seen = [];
        $deduped = [];

        foreach ($schedule as $send) {
            if ($send->input() !== [] || $send->meta() !== []) {
                $deduped[] = $send;

                continue;
            }

            $key = $send->node().'|'.json_encode($send->input()).'|'.json_encode($send->meta());

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $send;
        }

        return $deduped;
    }
}
