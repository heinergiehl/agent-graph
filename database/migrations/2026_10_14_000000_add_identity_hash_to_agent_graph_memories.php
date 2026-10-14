<?php

use Heiner\AgentGraph\Memory\MemoryIdentity;
use Heiner\AgentGraph\Persistence\AgentGraphMigration;
use Heiner\AgentGraph\Support\AgentGraphDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/*
 * SQL unique indexes treat NULLs as distinct, so the composite memory unique
 * index never covered records without a tenant. A unique SHA-256 of the full
 * identity tuple closes that gap on every driver. Existing records are
 * backfilled; if concurrent writes already produced duplicates for one
 * identity, only the oldest record receives the hash and the others are kept
 * unchanged, so no memory is deleted by this migration.
 */
return new class extends AgentGraphMigration
{
    private const COLUMN = 'identity_hash';

    private const INDEX = 'agent_graph_memory_identity_unique';

    public function up(): void
    {
        $schema = $this->schema();
        $table = $this->table();

        if (! $schema->hasTable($table)) {
            throw new RuntimeException("Cannot add identity_hash because AgentGraph memory table [{$table}] does not exist.");
        }

        if (! $schema->hasColumn($table, self::COLUMN)) {
            $schema->table($table, function (Blueprint $table): void {
                $table->char(self::COLUMN, 64)->nullable();
            });
        }

        $this->backfill($schema, $table);

        if (! $schema->hasIndex($table, self::INDEX)) {
            $schema->table($table, function (Blueprint $table): void {
                $table->unique(self::COLUMN, self::INDEX);
            });
        }
    }

    public function down(): void
    {
        // The nullable hash is compatible with older code and keeps the
        // uniqueness evidence when duplicate published copies exist.
    }

    private function backfill(Builder $schema, string $table): void
    {
        $connection = $schema->getConnection();

        $connection->table($table)
            ->whereNull(self::COLUMN)
            ->select(['id', 'scope_type', 'scope_id', 'tenant_id', 'namespace', 'key'])
            ->chunkById(500, function ($records) use ($connection, $table): void {
                $hashes = [];

                foreach ($records as $record) {
                    $hash = MemoryIdentity::hashParts(
                        (string) $record->scope_type,
                        (string) $record->scope_id,
                        $record->tenant_id === null ? null : (string) $record->tenant_id,
                        (string) $record->namespace,
                        (string) $record->key,
                    );

                    $hashes[$hash] ??= (int) $record->id;
                }

                $taken = $connection->table($table)
                    ->whereIn(self::COLUMN, array_keys($hashes))
                    ->pluck(self::COLUMN)
                    ->all();

                foreach (array_diff_key($hashes, array_flip($taken)) as $hash => $id) {
                    $connection->table($table)->where('id', $id)->update([self::COLUMN => $hash]);
                }
            });
    }

    private function schema(): Builder
    {
        return Schema::connection(AgentGraphDatabase::connectionName());
    }

    private function table(): string
    {
        return (string) config('agent-graph.tables.memories', 'agent_graph_memories');
    }
};
