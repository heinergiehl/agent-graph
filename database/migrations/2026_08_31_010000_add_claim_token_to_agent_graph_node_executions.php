<?php

use Heiner\AgentGraph\Persistence\AgentGraphMigration;
use Heiner\AgentGraph\Persistence\ClaimTokenColumnDefinition;
use Heiner\AgentGraph\Support\AgentGraphDatabase;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

return new class extends AgentGraphMigration
{
    private const MIGRATION_SUFFIX = '_add_claim_token_to_agent_graph_node_executions';

    public function up(): void
    {
        $schema = $this->schema();
        $table = $this->table();

        if (! $schema->hasTable($table)) {
            throw new RuntimeException("Cannot add claim_token because AgentGraph node-execution table [{$table}] does not exist.");
        }

        $column = ClaimTokenColumnDefinition::find($schema->getColumns($table));

        if ($column !== null) {
            ClaimTokenColumnDefinition::assertCompatible($column, $schema->getConnection()->getDriverName(), $table);

            return;
        }

        $schema->table($table, function (Blueprint $table): void {
            $table->string('claim_token', 26)->nullable()->after('locked_until');
        });

        $column = ClaimTokenColumnDefinition::find($schema->getColumns($table));

        if ($column === null) {
            throw new RuntimeException("AgentGraph claim_token migration completed without creating [{$table}.claim_token].");
        }

        ClaimTokenColumnDefinition::assertCompatible($column, $schema->getConnection()->getDriverName(), $table);
    }

    public function down(): void
    {
        $schema = $this->schema();
        $table = $this->table();

        if (! $schema->hasTable($table)) {
            return;
        }

        $column = ClaimTokenColumnDefinition::find($schema->getColumns($table));

        if ($column === null) {
            return;
        }

        ClaimTokenColumnDefinition::assertCompatible($column, $schema->getConnection()->getDriverName(), $table);

        if ($this->anotherRecordedMigrationOwnsTheColumn($schema->getConnection())) {
            return;
        }

        $schema->table($table, function (Blueprint $table): void {
            $table->dropColumn('claim_token');
        });
    }

    private function schema(): Builder
    {
        return Schema::connection(AgentGraphDatabase::connectionName());
    }

    private function table(): string
    {
        return (string) config('agent-graph.tables.node_executions', 'agent_graph_node_executions');
    }

    private function anotherRecordedMigrationOwnsTheColumn(Connection $connection): bool
    {
        $migrations = config('database.migrations', 'migrations');
        $migrationTable = is_array($migrations)
            ? (string) ($migrations['table'] ?? 'migrations')
            : (string) $migrations;

        if (! $connection->getSchemaBuilder()->hasTable($migrationTable)) {
            return false;
        }

        $file = (new ReflectionClass($this))->getFileName();
        $currentMigration = $file === false ? '' : pathinfo($file, PATHINFO_FILENAME);

        foreach ($connection->table($migrationTable)->pluck('migration') as $recordedMigration) {
            if (is_string($recordedMigration)
                && $recordedMigration !== $currentMigration
                && str_ends_with($recordedMigration, self::MIGRATION_SUFFIX)) {
                return true;
            }
        }

        return false;
    }
};
