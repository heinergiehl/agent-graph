<?php

namespace Heiner\AgentGraph\Support;

use Illuminate\Foundation\Bus\PendingDispatch;

final class AgentGraphQueue
{
    public static function connection(): ?string
    {
        $connection = config('agent-graph.execution.queue_connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public static function queue(): ?string
    {
        $queue = config('agent-graph.execution.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    public static function configure(PendingDispatch $dispatch): PendingDispatch
    {
        if ($connection = self::connection()) {
            $dispatch->onConnection($connection);
        }

        if ($queue = self::queue()) {
            $dispatch->onQueue($queue);
        }

        return $dispatch;
    }
}
