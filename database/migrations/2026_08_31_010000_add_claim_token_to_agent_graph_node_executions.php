<?php

use Heiner\AgentGraph\Persistence\AgentGraphMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends AgentGraphMigration
{
    public function up(): void
    {
        Schema::table(config('agent-graph.tables.node_executions', 'agent_graph_node_executions'), function (Blueprint $table): void {
            $table->string('claim_token', 26)->nullable()->after('locked_until');
        });
    }

    public function down(): void
    {
        Schema::table(config('agent-graph.tables.node_executions', 'agent_graph_node_executions'), function (Blueprint $table): void {
            $table->dropColumn('claim_token');
        });
    }
};
