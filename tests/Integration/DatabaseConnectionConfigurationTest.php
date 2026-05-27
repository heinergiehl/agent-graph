<?php

use Heiner\AgentGraph\Memory\MemoryScope;
use Heiner\AgentGraph\Memory\PgvectorMemoryStore;
use Heiner\AgentGraph\Persistence\DatabaseCheckpointStore;
use Heiner\AgentGraph\Persistence\DatabaseInterruptStore;
use Heiner\AgentGraph\Persistence\DatabaseMemoryStore;
use Heiner\AgentGraph\Persistence\DatabaseNodeExecutionStore;
use Heiner\AgentGraph\Persistence\DatabaseRunStore;
use Heiner\AgentGraph\Persistence\DatabaseTaskStore;
use Heiner\AgentGraph\Persistence\DatabaseTraceStore;
use Heiner\AgentGraph\Persistence\DatabaseWriteStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->agentGraphConnection = 'agent_graph_configured';

    config()->set('database.connections.'.$this->agentGraphConnection, [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('agent-graph.database.connection', $this->agentGraphConnection);

    DB::purge($this->agentGraphConnection);

    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
});

it('uses the configured database connection for all database stores', function () {
    expect(Schema::connection(config('database.default'))->hasTable(config('agent-graph.tables.runs')))->toBeFalse()
        ->and(Schema::connection($this->agentGraphConnection)->hasTable(config('agent-graph.tables.runs')))->toBeTrue();

    $runs = new DatabaseRunStore(app('db'));
    $checkpoints = new DatabaseCheckpointStore(app('db'));
    $interrupts = new DatabaseInterruptStore(app('db'));
    $writes = new DatabaseWriteStore(app('db'));
    $tasks = new DatabaseTaskStore(app('db'));
    $memory = new DatabaseMemoryStore(app('db'));
    $traces = new DatabaseTraceStore(app('db'));
    $nodeExecutions = new DatabaseNodeExecutionStore(app('db'));

    $run = $runs->create('support_triage', '1', 'thread-configured', ['input' => 'Hi']);
    $checkpoint = $checkpoints->create([
        'run_id' => $run['public_id'],
        'thread_id' => 'thread-configured',
        'graph_key' => 'support_triage',
        'graph_version' => '1',
        'step' => 1,
        'state' => ['answer' => 'Hello'],
        'next_nodes' => ['__end__'],
        'completed_nodes' => ['answer'],
    ]);

    $writes->createMany($run['public_id'], $checkpoint['checkpoint_id'], 'answer', ['answer' => 'Hello']);
    $interrupt = $interrupts->create([
        'run_id' => $run['public_id'],
        'checkpoint_id' => $checkpoint['checkpoint_id'],
        'node_id' => 'answer',
        'type' => 'input',
        'payload' => ['prompt' => 'Continue?'],
    ]);
    $task = $tasks->start('configured-task', 'hash', ['input' => true], ['run_id' => $run['public_id']]);
    $memoryRecord = $memory->write(MemoryScope::actor('tenant-configured', 'user-configured'), 'preferences', 'language', 'de');
    $trace = $traces->record($run['public_id'], 'configured.trace', ['ok' => true]);
    $execution = $nodeExecutions->schedule([
        'run_id' => $run['public_id'],
        'checkpoint_id' => $checkpoint['checkpoint_id'],
        'step' => 1,
        'node_id' => 'answer',
        'status' => 'pending',
    ]);

    expect($runs->find($run['public_id'])['public_id'])->toBe($run['public_id'])
        ->and($checkpoints->find($checkpoint['checkpoint_id'])['checkpoint_id'])->toBe($checkpoint['checkpoint_id'])
        ->and($interrupts->find($interrupt['interrupt_id'])['interrupt_id'])->toBe($interrupt['interrupt_id'])
        ->and($writes->listForRun($run['public_id']))->toHaveCount(1)
        ->and($tasks->findByKey($task['task_key'])['task_key'])->toBe('configured-task')
        ->and($memory->read([MemoryScope::actor('tenant-configured', 'user-configured')], 'preferences', 'language')['id'])->toBe($memoryRecord['id'])
        ->and($traces->listForRun($run['public_id'])[0]['id'])->toBe($trace['id'])
        ->and($nodeExecutions->find($execution['execution_id'])['execution_id'])->toBe($execution['execution_id']);
});

it('uses the configured database connection for pruning', function () {
    $connection = DB::connection($this->agentGraphConnection);
    $traces = config('agent-graph.tables.traces');
    $tasks = config('agent-graph.tables.tasks');
    $memories = config('agent-graph.tables.memories');
    $old = now()->subDays(45);
    $fresh = now();

    $connection->table($traces)->insert([
        ['run_id' => 'run_old', 'event' => 'old', 'payload' => null, 'meta' => null, 'created_at' => $old, 'updated_at' => $old],
        ['run_id' => 'run_fresh', 'event' => 'fresh', 'payload' => null, 'meta' => null, 'created_at' => $fresh, 'updated_at' => $fresh],
    ]);

    $connection->table($tasks)->insert([
        ['task_key' => 'old-task', 'status' => 'completed', 'input_hash' => 'hash-old', 'input' => '{}', 'attempts' => 1, 'created_at' => $old, 'updated_at' => $old],
        ['task_key' => 'fresh-task', 'status' => 'completed', 'input_hash' => 'hash-fresh', 'input' => '{}', 'attempts' => 1, 'created_at' => $fresh, 'updated_at' => $fresh],
    ]);

    $connection->table($memories)->insert([
        [
            'scope_type' => 'actor',
            'scope_id' => 'user-old',
            'tenant_id' => 'tenant',
            'namespace' => 'preferences',
            'key' => 'expired',
            'memory_type' => 'preference',
            'value' => '"old"',
            'content' => 'Old preference',
            'expires_at' => now()->subMinute(),
            'usage_count' => 0,
            'created_at' => $old,
            'updated_at' => $old,
        ],
        [
            'scope_type' => 'actor',
            'scope_id' => 'user-fresh',
            'tenant_id' => 'tenant',
            'namespace' => 'preferences',
            'key' => 'fresh',
            'memory_type' => 'preference',
            'value' => '"fresh"',
            'content' => 'Fresh preference',
            'expires_at' => now()->addDay(),
            'usage_count' => 0,
            'created_at' => $fresh,
            'updated_at' => $fresh,
        ],
    ]);

    $this->artisan('agent-graph:prune --traces --tasks --memories --days=30')
        ->expectsOutputToContain('traces pruned: 1')
        ->expectsOutputToContain('tasks pruned: 1')
        ->expectsOutputToContain('expired memories pruned: 1')
        ->assertSuccessful();

    expect($connection->table($traces)->where('event', 'old')->exists())->toBeFalse()
        ->and($connection->table($traces)->where('event', 'fresh')->exists())->toBeTrue()
        ->and($connection->table($tasks)->where('task_key', 'old-task')->exists())->toBeFalse()
        ->and($connection->table($tasks)->where('task_key', 'fresh-task')->exists())->toBeTrue()
        ->and($connection->table($memories)->where('key', 'expired')->exists())->toBeFalse()
        ->and($connection->table($memories)->where('key', 'fresh')->exists())->toBeTrue();
});

it('uses the configured database connection for doctor table checks', function () {
    $this->artisan('agent-graph:doctor')
        ->expectsOutputToContain('Database connection: '.$this->agentGraphConnection)
        ->expectsOutputToContain('runs table [agent_graph_runs]: present')
        ->assertSuccessful();
});

it('uses the configured database connection for pgvector memory writes', function () {
    config()->set('agent-graph.vector_memory.table', 'agent_graph_vector_memory_test');

    Schema::connection($this->agentGraphConnection)->create('agent_graph_vector_memory_test', function (Blueprint $table): void {
        $table->id();
        $table->string('scope_type');
        $table->string('scope_id');
        $table->string('tenant_id')->nullable();
        $table->string('namespace');
        $table->string('key');
        $table->text('embedding')->nullable();
        $table->text('memory');
        $table->timestamps();
    });

    $store = new PgvectorMemoryStore(app('db'));
    $scope = MemoryScope::thread('thread-pgvector', 'tenant-pgvector');

    $record = $store->upsert($scope, 'configured-pgvector', 'near', [1.0, 0.0, 0.0], ['value' => 'near']);

    expect($record['memory'])->toBe(['value' => 'near'])
        ->and(DB::connection($this->agentGraphConnection)->table('agent_graph_vector_memory_test')->where('namespace', 'configured-pgvector')->exists())->toBeTrue()
        ->and(Schema::connection(config('database.default'))->hasTable('agent_graph_vector_memory_test'))->toBeFalse();
});
