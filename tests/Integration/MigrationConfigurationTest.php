<?php

use Heiner\AgentGraph\Support\AgentGraphDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('migrates and rolls back custom named tables on the configured database connection', function () {
    $connection = 'agent_graph_migration_custom';
    $tables = [
        'runs' => 'custom_agent_graph_runs',
        'checkpoints' => 'custom_agent_graph_checkpoints',
        'writes' => 'custom_agent_graph_writes',
        'tasks' => 'custom_agent_graph_tasks',
        'interrupts' => 'custom_agent_graph_interrupts',
        'memories' => 'custom_agent_graph_memories',
        'node_executions' => 'custom_agent_graph_node_executions',
        'traces' => 'custom_agent_graph_traces',
    ];

    config()->set('database.connections.'.$connection, [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('agent-graph.database.connection', $connection);
    config()->set('agent-graph.tables', $tables);

    DB::purge($connection);
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::connection($connection)->hasTable($tables['runs']))->toBeTrue()
        ->and(Schema::connection(config('database.default'))->hasTable($tables['runs']))->toBeFalse();

    expect(sqliteIndexNames($connection, $tables['checkpoints']))
        ->toContain('agent_graph_checkpoints_run_step_unique')
        ->and(sqliteIndexNames($connection, $tables['node_executions']))
        ->toContain('agent_graph_node_executions_schedule_unique')
        ->and(sqliteIndexNames($connection, $tables['interrupts']))
        ->toContain('agent_graph_interrupts_run_status_index');

    $this->artisan('migrate:rollback', [
        '--path' => realpath(__DIR__.'/../../database/migrations'),
        '--realpath' => true,
    ])->assertSuccessful();

    foreach ($tables as $table) {
        expect(Schema::connection($connection)->hasTable($table))->toBeFalse();
    }
});

it('uses the configured AgentGraph database connection in the pgvector migration stub statements', function () {
    $stub = file_get_contents(__DIR__.'/../../stubs/pgvector-memory-migration.stub');

    expect($stub)->toContain(AgentGraphDatabase::class)
        ->and($stub)->toContain("DB::connection(AgentGraphDatabase::connectionName())->statement('create extension if not exists vector')")
        ->and($stub)->toContain('Schema::connection(AgentGraphDatabase::connectionName())->create')
        ->and($stub)->toContain('$table = DB::connection(AgentGraphDatabase::connectionName())->getQueryGrammar()->wrapTable')
        ->and($stub)->toContain('DB::connection(AgentGraphDatabase::connectionName())->statement("alter table {$table} add column embedding vector")');
});

function sqliteIndexNames(string $connection, string $table): array
{
    $table = str_replace("'", "''", $table);

    return array_map(
        fn (object $index): string => $index->name,
        DB::connection($connection)->select("PRAGMA index_list('{$table}')"),
    );
}
