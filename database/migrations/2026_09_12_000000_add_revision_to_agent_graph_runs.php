<?php

use Heiner\AgentGraph\Persistence\AgentGraphMigration;
use Heiner\AgentGraph\Support\AgentGraphDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends AgentGraphMigration
{
    public function up(): void
    {
        $schema = Schema::connection(AgentGraphDatabase::connectionName());
        $name = config('agent-graph.tables.runs', 'agent_graph_runs');
        if (! $schema->hasColumn($name, 'revision')) {
            $schema->table($name, fn (Blueprint $table) => $table->unsignedBigInteger('revision')->default(0));
        }
    }

    public function down(): void
    {
        // Ownership revisions must survive code rollback and duplicate published migrations.
    }
};
