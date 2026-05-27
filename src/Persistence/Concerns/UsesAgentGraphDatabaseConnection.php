<?php

namespace Heiner\AgentGraph\Persistence\Concerns;

use Heiner\AgentGraph\Support\AgentGraphDatabase;
use Illuminate\Database\Query\Builder;

trait UsesAgentGraphDatabaseConnection
{
    abstract protected function table(): string;

    protected function query(): Builder
    {
        return $this->connection()->table($this->table());
    }

    protected function connection()
    {
        return $this->db->connection(AgentGraphDatabase::connectionName());
    }
}
