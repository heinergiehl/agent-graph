<?php

namespace Heiner\AgentGraph\Persistence;

use Heiner\AgentGraph\Support\AgentGraphDatabase;
use Illuminate\Database\Migrations\Migration;

abstract class AgentGraphMigration extends Migration
{
    public function getConnection(): ?string
    {
        return AgentGraphDatabase::connectionName();
    }
}
