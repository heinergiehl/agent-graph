<?php

use Heiner\AgentGraph\Persistence\AgentGraphMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends AgentGraphMigration
{
    public function up(): void
    {
        Schema::table(config('agent-graph.tables.node_executions', 'agent_graph_node_executions'), function (Blueprint $table): void {
            $table->string('execution_id')->nullable()->unique()->after('id');
            $table->string('checkpoint_id')->nullable()->index()->after('run_id');
            $table->longText('base_state')->nullable()->after('node_id');
            $table->longText('node_state')->nullable()->after('base_state');
            $table->longText('resume_payload')->nullable()->after('node_state');
            $table->string('interrupt_id')->nullable()->index()->after('resume_payload');
            $table->timestamp('locked_until')->nullable()->index()->after('error');
            $table->timestamp('started_at')->nullable()->after('locked_until');
            $table->timestamp('finished_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table(config('agent-graph.tables.node_executions', 'agent_graph_node_executions'), function (Blueprint $table): void {
            $table->dropUnique('agent_graph_node_executions_execution_id_unique');
            $table->dropIndex('agent_graph_node_executions_checkpoint_id_index');
            $table->dropIndex('agent_graph_node_executions_interrupt_id_index');
            $table->dropIndex('agent_graph_node_executions_locked_until_index');
            $table->dropColumn([
                'execution_id',
                'checkpoint_id',
                'base_state',
                'node_state',
                'resume_payload',
                'interrupt_id',
                'locked_until',
                'started_at',
                'finished_at',
            ]);
        });
    }
};
