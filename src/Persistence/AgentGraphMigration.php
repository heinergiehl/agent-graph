<?php

namespace Heiner\AgentGraph\Persistence;

use Illuminate\Database\Migrations\Migration;

abstract class AgentGraphMigration extends Migration
{
    public function getConnection(): ?string
    {
        return config('agent-graph.database.connection', config('database.default'));
    }
}
