<?php

use Heiner\AgentGraph\Persistence\ClaimTokenColumnDefinition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if (env('AGENT_GRAPH_POSTGRES_MIGRATION_TEST') !== '1') {
        $this->markTestSkipped('Set AGENT_GRAPH_POSTGRES_MIGRATION_TEST=1 to run PostgreSQL claim-token migration tests.');
    }

    $this->postgresClaimTokenConnection = 'agent_graph_postgres_migration';
    $this->postgresClaimTokenTable = 'agent_graph_claim_token_'.str()->lower(str()->random(10));
    $this->originalAgentGraphConnection = config('agent-graph.database.connection');
    $this->originalAgentGraphNodeExecutionsTable = config('agent-graph.tables.node_executions');

    config([
        'database.connections.'.$this->postgresClaimTokenConnection => [
            'driver' => 'pgsql',
            'host' => env('AGENT_GRAPH_POSTGRES_MIGRATION_HOST', '127.0.0.1'),
            'port' => env('AGENT_GRAPH_POSTGRES_MIGRATION_PORT', '55437'),
            'database' => env('AGENT_GRAPH_POSTGRES_MIGRATION_DATABASE', 'agent_graph'),
            'username' => env('AGENT_GRAPH_POSTGRES_MIGRATION_USERNAME', 'postgres'),
            'password' => env('AGENT_GRAPH_POSTGRES_MIGRATION_PASSWORD', 'postgres'),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
        ],
        'agent-graph.database.connection' => $this->postgresClaimTokenConnection,
        'agent-graph.tables.node_executions' => $this->postgresClaimTokenTable,
    ]);

    DB::purge($this->postgresClaimTokenConnection);
    DB::reconnect($this->postgresClaimTokenConnection);

    Schema::connection($this->postgresClaimTokenConnection)->create(
        $this->postgresClaimTokenTable,
        function (Blueprint $table): void {
            $table->id();
            $table->timestamp('locked_until')->nullable();
        },
    );
});

afterEach(function () {
    if (isset($this->postgresClaimTokenConnection, $this->postgresClaimTokenTable)) {
        Schema::connection($this->postgresClaimTokenConnection)->dropIfExists($this->postgresClaimTokenTable);
        DB::purge($this->postgresClaimTokenConnection);
        config([
            'agent-graph.database.connection' => $this->originalAgentGraphConnection,
            'agent-graph.tables.node_executions' => $this->originalAgentGraphNodeExecutionsTable,
        ]);
    }
});

it('runs the claim token migration against fresh and compatible PostgreSQL schemas and rejects drift', function () {
    $schema = Schema::connection($this->postgresClaimTokenConnection);
    $table = $this->postgresClaimTokenTable;
    $migrationPath = realpath(__DIR__.'/../../database/migrations/2026_08_31_010000_add_claim_token_to_agent_graph_node_executions.php');
    $migration = require $migrationPath;

    $migration->up();
    $column = ClaimTokenColumnDefinition::find($schema->getColumns($table));
    expect($column['type'])->toBe('character varying(26)')
        ->and($column['nullable'])->toBeTrue();

    $migration->up();
    expect(ClaimTokenColumnDefinition::find($schema->getColumns($table)))
        ->toMatchArray(['type' => 'character varying(26)', 'nullable' => true]);

    $migration->down();
    expect($schema->hasColumn($table, ClaimTokenColumnDefinition::NAME))->toBeFalse();

    $schema->table($table, function (Blueprint $table): void {
        $table->string(ClaimTokenColumnDefinition::NAME, 25)->nullable();
    });

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'is incompatible')
        ->and($schema->getColumnType($table, ClaimTokenColumnDefinition::NAME, true))
        ->toBe('character varying(25)');
})->group('postgres-migration');
