<?php

use Heiner\AgentGraph\Persistence\AgentGraphMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends AgentGraphMigration
{
    public function up(): void
    {
        Schema::create(config('agent-graph.tables.runs', 'agent_graph_runs'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->string('thread_id')->index();
            $table->string('graph_key')->index();
            $table->string('graph_version')->default('1');
            $table->string('status')->index();
            $table->string('current_checkpoint_id')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('resume_at')->nullable();
            $table->longText('input')->nullable();
            $table->longText('error')->nullable();
            $table->longText('meta')->nullable();
            $table->timestamps();
        });

        Schema::create(config('agent-graph.tables.checkpoints', 'agent_graph_checkpoints'), function (Blueprint $table): void {
            $table->id();
            $table->string('checkpoint_id')->unique();
            $table->string('parent_checkpoint_id')->nullable()->index();
            $table->string('run_id')->index();
            $table->string('thread_id')->index();
            $table->string('graph_key')->index();
            $table->string('graph_version')->default('1');
            $table->unsignedInteger('step')->index();
            $table->longText('state');
            $table->longText('next_nodes');
            $table->longText('completed_nodes');
            $table->longText('interrupts')->nullable();
            $table->longText('meta')->nullable();
            $table->timestamps();
        });

        Schema::create(config('agent-graph.tables.writes', 'agent_graph_writes'), function (Blueprint $table): void {
            $table->id();
            $table->string('checkpoint_id')->index();
            $table->string('run_id')->index();
            $table->string('node_id')->index();
            $table->string('channel')->index();
            $table->string('key')->nullable();
            $table->longText('value')->nullable();
            $table->string('reducer')->nullable();
            $table->longText('meta')->nullable();
            $table->timestamps();
        });

        Schema::create(config('agent-graph.tables.tasks', 'agent_graph_tasks'), function (Blueprint $table): void {
            $table->id();
            $table->string('task_key')->unique();
            $table->string('run_id')->nullable()->index();
            $table->string('checkpoint_id')->nullable()->index();
            $table->string('node_id')->nullable()->index();
            $table->string('status')->index();
            $table->string('input_hash');
            $table->longText('input');
            $table->longText('result')->nullable();
            $table->longText('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->longText('meta')->nullable();
            $table->timestamps();
        });

        Schema::create(config('agent-graph.tables.interrupts', 'agent_graph_interrupts'), function (Blueprint $table): void {
            $table->id();
            $table->string('interrupt_id')->unique();
            $table->string('run_id')->index();
            $table->string('checkpoint_id')->index();
            $table->string('node_id')->index();
            $table->string('type')->index();
            $table->string('status')->index();
            $table->longText('payload')->nullable();
            $table->longText('response')->nullable();
            $table->string('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create(config('agent-graph.tables.memories', 'agent_graph_memories'), function (Blueprint $table): void {
            $table->id();
            $table->string('scope_type')->index();
            $table->string('scope_id')->index();
            $table->string('tenant_id')->nullable()->index();
            $table->string('namespace')->index();
            $table->string('key')->index();
            $table->string('memory_type')->index();
            $table->longText('value')->nullable();
            $table->longText('content')->nullable();
            $table->float('confidence')->nullable();
            $table->string('source')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->longText('meta')->nullable();
            $table->timestamps();
            $table->unique(['scope_type', 'scope_id', 'tenant_id', 'namespace', 'key'], 'agent_graph_memory_unique');
        });

        Schema::create(config('agent-graph.tables.traces', 'agent_graph_traces'), function (Blueprint $table): void {
            $table->id();
            $table->string('run_id')->nullable()->index();
            $table->string('event')->index();
            $table->longText('payload')->nullable();
            $table->longText('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (array_reverse(config('agent-graph.tables')) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
