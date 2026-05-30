<?php

use Heiner\AgentGraph\Persistence\AgentGraphMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends AgentGraphMigration
{
    public function up(): void
    {
        Schema::table(config('agent-graph.tables.checkpoints', 'agent_graph_checkpoints'), function (Blueprint $table): void {
            $table->unique(['run_id', 'step'], 'agent_graph_checkpoints_run_step_unique');
        });

        Schema::table(config('agent-graph.tables.node_executions', 'agent_graph_node_executions'), function (Blueprint $table): void {
            $table->unique(['run_id', 'step', 'schedule_index'], 'agent_graph_node_executions_schedule_unique');
        });

        Schema::table(config('agent-graph.tables.interrupts', 'agent_graph_interrupts'), function (Blueprint $table): void {
            $table->index(['run_id', 'status'], 'agent_graph_interrupts_run_status_index');
        });
    }

    public function down(): void
    {
        Schema::table(config('agent-graph.tables.interrupts', 'agent_graph_interrupts'), function (Blueprint $table): void {
            $table->dropIndex('agent_graph_interrupts_run_status_index');
        });

        Schema::table(config('agent-graph.tables.node_executions', 'agent_graph_node_executions'), function (Blueprint $table): void {
            $table->dropUnique('agent_graph_node_executions_schedule_unique');
        });

        Schema::table(config('agent-graph.tables.checkpoints', 'agent_graph_checkpoints'), function (Blueprint $table): void {
            $table->dropUnique('agent_graph_checkpoints_run_step_unique');
        });
    }
};
