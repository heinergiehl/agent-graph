<?php

use Heiner\AgentGraph\Persistence\AgentGraphMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends AgentGraphMigration
{
    public function up(): void
    {
        Schema::table(config('agent-graph.tables.interrupts', 'agent_graph_interrupts'), function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->index()->after('resolved_at');
        });

        Schema::create(config('agent-graph.tables.node_executions', 'agent_graph_node_executions'), function (Blueprint $table): void {
            $table->id();
            $table->string('run_id')->index();
            $table->unsignedInteger('step')->index();
            $table->unsignedInteger('schedule_index')->default(0);
            $table->string('node_id')->index();
            $table->string('status')->index();
            $table->longText('writes')->nullable();
            $table->longText('next_schedule')->nullable();
            $table->longText('interrupt')->nullable();
            $table->longText('error')->nullable();
            $table->longText('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('agent-graph.tables.node_executions', 'agent_graph_node_executions'));

        Schema::table(config('agent-graph.tables.interrupts', 'agent_graph_interrupts'), function (Blueprint $table): void {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
