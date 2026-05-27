<?php

namespace Heiner\AgentGraph\Support;

class AgentGraphDatabase
{
    public static function connectionName(): ?string
    {
        $connection = config('agent-graph.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public static function displayConnectionName(): string
    {
        return self::connectionName() ?? (string) config('database.default', 'default');
    }
}
