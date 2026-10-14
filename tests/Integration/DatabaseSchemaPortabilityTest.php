<?php

use Heiner\AgentGraph\Memory\MemoryIdentity;
use Heiner\AgentGraph\Memory\MemoryScope;
use Heiner\AgentGraph\Persistence\ClaimTokenColumnDefinition;
use Heiner\AgentGraph\Persistence\DatabaseMemoryStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Runs every published migration on a disposable MySQL, MariaDB or
 * PostgreSQL database (all its tables are dropped) and exercises the
 * driver-sensitive schema contracts. Opt in with
 * AGENT_GRAPH_SCHEMA_TEST_DRIVER=mysql|mariadb|pgsql and
 * AGENT_GRAPH_SCHEMA_TEST_HOST, _PORT, _DATABASE, _USERNAME, _PASSWORD.
 */
beforeEach(function () {
    $driver = (string) env('AGENT_GRAPH_SCHEMA_TEST_DRIVER', '');

    if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
        $this->markTestSkipped('Set AGENT_GRAPH_SCHEMA_TEST_DRIVER=mysql|mariadb|pgsql to run the schema portability tests.');
    }

    $this->schemaDriver = $driver;
    $this->schemaConnection = 'agent_graph_schema_portability';

    config([
        'database.connections.'.$this->schemaConnection => [
            'driver' => $driver,
            'host' => env('AGENT_GRAPH_SCHEMA_TEST_HOST', '127.0.0.1'),
            'port' => env('AGENT_GRAPH_SCHEMA_TEST_PORT', $driver === 'pgsql' ? '5432' : '3306'),
            'database' => env('AGENT_GRAPH_SCHEMA_TEST_DATABASE', 'agent_graph'),
            'username' => env('AGENT_GRAPH_SCHEMA_TEST_USERNAME', 'root'),
            'password' => env('AGENT_GRAPH_SCHEMA_TEST_PASSWORD', ''),
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
            'schema' => 'public',
            'strict' => true,
        ],
        'database.default' => $this->schemaConnection,
        'agent-graph.database.connection' => $this->schemaConnection,
    ]);

    DB::purge($this->schemaConnection);
    Schema::connection($this->schemaConnection)->dropAllTables();
});

afterEach(function () {
    if (isset($this->schemaConnection)) {
        Schema::connection($this->schemaConnection)->dropAllTables();
        DB::purge($this->schemaConnection);
    }
});

it('runs every migration and keeps the claim token and memory identity contracts', function () {
    $this->artisan('migrate', [
        '--database' => $this->schemaConnection,
        '--path' => realpath(__DIR__.'/../../database/migrations'),
        '--realpath' => true,
    ])->assertSuccessful();

    $schema = Schema::connection($this->schemaConnection);
    $executions = config('agent-graph.tables.node_executions');
    $memories = config('agent-graph.tables.memories');

    // MariaDB reports a nullable column without a default as the expression NULL.
    $column = ClaimTokenColumnDefinition::find($schema->getColumns($executions));
    ClaimTokenColumnDefinition::assertCompatible($column, $this->schemaDriver, $executions);
    (require __DIR__.'/../../database/migrations/2026_08_31_010000_add_claim_token_to_agent_graph_node_executions.php')->up();
    (require __DIR__.'/../../database/migrations/2026_10_14_000000_add_identity_hash_to_agent_graph_memories.php')->up();

    expect($schema->hasIndex($memories, 'agent_graph_memory_unique', 'unique'))->toBeTrue()
        ->and($schema->hasIndex($memories, 'agent_graph_memory_identity_unique', 'unique'))->toBeTrue();

    $store = new DatabaseMemoryStore(app('db'));
    $longest = new MemoryScope(str_repeat('t', 64), str_repeat('ü', 191), str_repeat('m', 128));
    $written = $store->write($longest, str_repeat('n', 128), str_repeat('😀', 255), ['plan' => 'pro']);
    $read = $store->read([$longest], str_repeat('n', 128), str_repeat('😀', 255));

    expect($read['id'])->toBe($written['id'])
        ->and($read['value'])->toBe(['plan' => 'pro']);

    expect(fn () => $store->write(MemoryScope::thread('thread'), 'facts', str_repeat('k', 256), 'value'))
        ->toThrow(InvalidArgumentException::class);

    $global = MemoryScope::global();
    $store->write($global, 'facts', 'plan', 'basic');
    $store->write($global, 'facts', 'plan', 'pro');

    expect(DB::connection($this->schemaConnection)->table($memories)->where('scope_type', 'global')->count())->toBe(1)
        ->and(fn () => DB::connection($this->schemaConnection)->table($memories)->insert([
            'scope_type' => 'global',
            'scope_id' => 'global',
            'tenant_id' => null,
            'namespace' => 'facts',
            'key' => 'plan',
            'identity_hash' => MemoryIdentity::hash($global, 'facts', 'plan'),
            'memory_type' => 'fact',
            'usage_count' => 0,
        ]))->toThrow(UniqueConstraintViolationException::class);
})->group('database-portability');
