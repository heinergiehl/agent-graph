<?php

use Heiner\AgentGraph\Persistence\ClaimTokenColumnDefinition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adds and removes the claim token column on a fresh upgrade', function () {
    $connection = 'claim_token_fresh_upgrade';
    $table = 'claim_token_fresh_node_executions';
    [$originalConnection, $originalTable] = configureClaimTokenMigrationTest($connection, $table);

    try {
        $this->artisan('migrate', [
            '--database' => $connection,
            '--path' => claimTokenPreMigrationPaths(),
            '--realpath' => true,
        ])->assertSuccessful();

        expect(Schema::connection($connection)->hasColumn($table, ClaimTokenColumnDefinition::NAME))->toBeFalse();

        $migration = claimTokenMigrationPath();
        $this->artisan('migrate', [
            '--database' => $connection,
            '--path' => [$migration],
            '--realpath' => true,
        ])->assertSuccessful();

        $schema = Schema::connection($connection);
        $column = ClaimTokenColumnDefinition::find($schema->getColumns($table));

        expect($column)->not->toBeNull();
        ClaimTokenColumnDefinition::assertCompatible($column, 'sqlite', $table);

        $this->artisan('migrate:rollback', [
            '--database' => $connection,
            '--path' => [$migration],
            '--realpath' => true,
            '--step' => 1,
        ])->assertSuccessful();

        expect($schema->hasColumn($table, ClaimTokenColumnDefinition::NAME))->toBeFalse();

        (require $migration)->down();
        expect($schema->hasColumn($table, ClaimTokenColumnDefinition::NAME))->toBeFalse();
    } finally {
        restoreClaimTokenMigrationTest($connection, $originalConnection, $originalTable);
    }
});

it('fails explicitly when the prerequisite node execution table is missing', function () {
    $connection = 'claim_token_missing_table';
    $table = 'claim_token_missing_node_executions';
    [$originalConnection, $originalTable] = configureClaimTokenMigrationTest($connection, $table);

    try {
        $migration = require claimTokenMigrationPath();

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, "table [{$table}] does not exist")
            ->and(Schema::connection($connection)->hasTable($table))
            ->toBeFalse();
    } finally {
        restoreClaimTokenMigrationTest($connection, $originalConnection, $originalTable);
    }
});

it('accepts a compatible column owned by an earlier published migration and preserves it on rollback', function () {
    $connection = 'claim_token_published_upgrade';
    $table = 'claim_token_published_node_executions';
    [$originalConnection, $originalTable] = configureClaimTokenMigrationTest($connection, $table);

    try {
        $this->artisan('migrate', [
            '--database' => $connection,
            '--path' => claimTokenPreMigrationPaths(),
            '--realpath' => true,
        ])->assertSuccessful();

        $schema = Schema::connection($connection);
        $schema->table($table, function (Blueprint $table): void {
            $table->string(ClaimTokenColumnDefinition::NAME, ClaimTokenColumnDefinition::LENGTH)->nullable();
        });

        $previousMigration = '2026_09_01_120000_add_claim_token_to_agent_graph_node_executions';
        DB::connection($connection)->table('migrations')->insert([
            'migration' => $previousMigration,
            'batch' => 1,
        ]);

        $migration = claimTokenMigrationPath();
        $this->artisan('migrate', [
            '--database' => $connection,
            '--path' => [$migration],
            '--realpath' => true,
        ])->assertSuccessful();

        $column = ClaimTokenColumnDefinition::find($schema->getColumns($table));
        expect($column)->not->toBeNull();
        ClaimTokenColumnDefinition::assertCompatible($column, 'sqlite', $table);

        $this->artisan('migrate:rollback', [
            '--database' => $connection,
            '--path' => [$migration],
            '--realpath' => true,
            '--step' => 1,
        ])->assertSuccessful();

        expect($schema->hasColumn($table, ClaimTokenColumnDefinition::NAME))->toBeTrue()
            ->and(DB::connection($connection)->table('migrations')->where('migration', $previousMigration)->exists())->toBeTrue();
    } finally {
        restoreClaimTokenMigrationTest($connection, $originalConnection, $originalTable);
    }
});

it('fails closed for incompatible existing claim token columns in both directions', function (string $definition) {
    $connection = 'claim_token_incompatible_'.$definition;
    $table = 'claim_token_incompatible_'.$definition.'_node_executions';
    [$originalConnection, $originalTable] = configureClaimTokenMigrationTest($connection, $table);

    try {
        $this->artisan('migrate', [
            '--database' => $connection,
            '--path' => claimTokenPreMigrationPaths(),
            '--realpath' => true,
        ])->assertSuccessful();

        Schema::connection($connection)->table($table, function (Blueprint $table) use ($definition): void {
            if ($definition === 'wrong_type') {
                $table->text(ClaimTokenColumnDefinition::NAME)->nullable();

                return;
            }

            $table->string(ClaimTokenColumnDefinition::NAME, ClaimTokenColumnDefinition::LENGTH);
        });

        $migration = require claimTokenMigrationPath();

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'is incompatible')
            ->and(fn () => $migration->down())
            ->toThrow(RuntimeException::class, 'is incompatible')
            ->and(Schema::connection($connection)->hasColumn($table, ClaimTokenColumnDefinition::NAME))
            ->toBeTrue();
    } finally {
        restoreClaimTokenMigrationTest($connection, $originalConnection, $originalTable);
    }
})->with(['wrong_type', 'not_nullable']);

it('requires the exact PostgreSQL claim token definition when adopting an existing column', function () {
    $compatible = [
        'name' => ClaimTokenColumnDefinition::NAME,
        'type_name' => 'varchar',
        'type' => 'character varying(26)',
        'nullable' => true,
        'default' => null,
        'auto_increment' => false,
        'generation' => null,
    ];

    ClaimTokenColumnDefinition::assertCompatible($compatible, 'pgsql', 'agent_graph_node_executions');

    $incompatibleDefinitions = [
        ['type' => 'character varying(25)'],
        ['type_name' => 'text', 'type' => 'text'],
        ['nullable' => false],
        ['default' => "'token'::character varying"],
        ['auto_increment' => true],
        ['generation' => ['type' => 'stored', 'expression' => "'token'::character varying"]],
    ];

    foreach ($incompatibleDefinitions as $definition) {
        expect(fn () => ClaimTokenColumnDefinition::assertCompatible(
            array_replace($compatible, $definition),
            'pgsql',
            'agent_graph_node_executions',
        ))->toThrow(RuntimeException::class, 'is incompatible');
    }
});

/** @return array{string|null, string} */
function configureClaimTokenMigrationTest(string $connection, string $table): array
{
    $originalConnection = config('agent-graph.database.connection');
    $originalTable = (string) config('agent-graph.tables.node_executions');

    config([
        'database.connections.'.$connection => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'agent-graph.database.connection' => $connection,
        'agent-graph.tables.node_executions' => $table,
    ]);
    DB::purge($connection);

    return [is_string($originalConnection) ? $originalConnection : null, $originalTable];
}

function restoreClaimTokenMigrationTest(string $connection, ?string $originalConnection, string $originalTable): void
{
    config([
        'agent-graph.database.connection' => $originalConnection,
        'agent-graph.tables.node_executions' => $originalTable,
    ]);
    DB::purge($connection);
}

/** @return list<string> */
function claimTokenPreMigrationPaths(): array
{
    $directory = realpath(__DIR__.'/../../database/migrations');

    return array_map(fn (string $name): string => $directory.DIRECTORY_SEPARATOR.$name, [
        '2026_05_25_000000_create_agent_graph_tables.php',
        '2026_05_26_000000_add_agent_graph_hardening_tables.php',
        '2026_05_26_010000_add_worker_fields_to_agent_graph_node_executions.php',
        '2026_05_30_000000_add_agent_graph_runtime_invariants.php',
    ]);
}

function claimTokenMigrationPath(): string
{
    return (string) realpath(__DIR__.'/../../database/migrations/2026_08_31_010000_add_claim_token_to_agent_graph_node_executions.php');
}
