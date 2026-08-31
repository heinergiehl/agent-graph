<?php

use Heiner\AgentGraph\Contracts\NodeExecutionStore;
use Heiner\AgentGraph\Exceptions\NodeExecutionClaimLostException;
use Heiner\AgentGraph\Persistence\DatabaseNodeExecutionStore;
use Heiner\AgentGraph\Persistence\InMemoryNodeExecutionStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
    Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00 UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

dataset('node ownership terminal methods', [
    'complete' => ['complete', 'completed', ['writes' => ['answer' => 'current result']]],
    'interrupt' => ['interrupt', 'interrupted', [
        'writes' => ['answer' => 'current result'],
        'interrupt' => ['type' => 'input', 'payload' => ['prompt' => 'Current review']],
    ]],
    'fail' => ['fail', 'failed', ['message' => 'current failure']],
]);

it('lets only the current node owner finish once and preserves terminal claims', function (string $driver, string $method, string $status, array $payload) {
    $store = nodeOwnershipStore($driver);
    $scheduled = scheduleNodeOwnershipExecution($store);
    $claim = $store->claim($scheduled['execution_id'], now()->addMinute());

    expect($scheduled['claim_token'])->toBeNull()
        ->and(Str::isUlid($claim['claim_token']))->toBeTrue()
        ->and($store->claim($claim['execution_id'], now()->addMinutes(2)))->toBeNull()
        ->and($store->find($claim['execution_id'])['claim_token'])->toBe($claim['claim_token']);

    $finished = $store->{$method}($claim['execution_id'], $claim['claim_token'], $payload);

    expect($finished['status'])->toBe($status)
        ->and($finished['claim_token'])->toBe($claim['claim_token'])
        ->and($finished['locked_until'])->toBeNull()
        ->and($finished['finished_at'])->not->toBeNull()
        ->and($finished)->toMatchArray($method === 'fail' ? ['error' => $payload] : $payload)
        ->and($store->claim($claim['execution_id'], now()->addHour()))->toEqual($finished);

    expect(fn () => $store->{$method}($claim['execution_id'], $claim['claim_token'], $payload))
        ->toThrow(NodeExecutionClaimLostException::class);
    expect($store->find($claim['execution_id']))->toEqual($finished);
})->with(['memory', 'sqlite'])->with('node ownership terminal methods');

it('rejects a stale node owner while a replacement claim is running', function (string $driver, string $method, string $status, array $payload) {
    $store = nodeOwnershipStore($driver);
    $scheduled = scheduleNodeOwnershipExecution($store);
    $old = $store->claim($scheduled['execution_id'], now()->addSecond());
    Carbon::setTestNow(now()->addSeconds(2));
    $current = $store->claim($scheduled['execution_id'], now()->addMinute());

    expect($current['claim_token'])->not->toBe($old['claim_token'])
        ->and(Str::isUlid($current['claim_token']))->toBeTrue();
    expect(fn () => $store->{$method}($old['execution_id'], $old['claim_token'], $payload))
        ->toThrow(NodeExecutionClaimLostException::class);
    expect($store->find($old['execution_id']))->toEqual($current);

    $finished = $store->{$method}($current['execution_id'], $current['claim_token'], $payload);

    expect($finished['status'])->toBe($status)
        ->and($finished['claim_token'])->toBe($current['claim_token'])
        ->and($finished)->toMatchArray($method === 'fail' ? ['error' => $payload] : $payload);
})->with(['memory', 'sqlite'])->with('node ownership terminal methods');

it('preserves a replacement worker success against every stale terminal outcome', function (string $driver, string $method, string $status, array $payload) {
    $store = nodeOwnershipStore($driver);
    $scheduled = scheduleNodeOwnershipExecution($store);
    $old = $store->claim($scheduled['execution_id'], now()->addSecond());
    Carbon::setTestNow(now()->addSeconds(2));
    $current = $store->claim($scheduled['execution_id'], now()->addMinute());
    $winner = $store->complete($current['execution_id'], $current['claim_token'], [
        'writes' => ['answer' => 'winning result'],
        'meta' => ['worker' => 'replacement'],
    ]);

    expect(fn () => $store->{$method}($old['execution_id'], $old['claim_token'], $payload))
        ->toThrow(NodeExecutionClaimLostException::class);
    expect($store->find($old['execution_id']))->toEqual($winner)
        ->and($winner['status'])->toBe('completed')
        ->and($winner['writes'])->toBe(['answer' => 'winning result'])
        ->and($winner['error'])->toBeNull();
})->with(['memory', 'sqlite'])->with('node ownership terminal methods');

it('uses a fresh ownership token even when two expired leases have the same timestamp', function (string $driver) {
    $store = nodeOwnershipStore($driver);
    $scheduled = scheduleNodeOwnershipExecution($store);
    $leaseUntil = now()->subSecond();
    $old = $store->claim($scheduled['execution_id'], $leaseUntil);
    $current = $store->claim($scheduled['execution_id'], $leaseUntil);

    expect((string) $current['locked_until'])->toBe((string) $old['locked_until'])
        ->and($current['claim_token'])->not->toBe($old['claim_token']);
    expect(fn () => $store->complete($old['execution_id'], $old['claim_token'], ['writes' => ['answer' => 'stale']]))
        ->toThrow(NodeExecutionClaimLostException::class);

    // Expiry permits reclaim; until another claim wins, this owner may still finish.
    $finished = $store->complete($current['execution_id'], $current['claim_token'], ['writes' => ['answer' => 'current']]);

    expect($finished['status'])->toBe('completed')
        ->and($finished['writes'])->toBe(['answer' => 'current']);
})->with(['memory', 'sqlite']);

it('rejects empty or fabricated tokens for unclaimed node executions', function (string $driver) {
    $store = nodeOwnershipStore($driver);
    $scheduled = scheduleNodeOwnershipExecution($store);

    foreach (['', (string) Str::ulid()] as $token) {
        foreach (['complete', 'interrupt', 'fail'] as $method) {
            expect(fn () => $store->{$method}($scheduled['execution_id'], $token, []))
                ->toThrow(NodeExecutionClaimLostException::class);
        }
    }

    expect($store->find($scheduled['execution_id']))->toEqual($scheduled);
})->with(['memory', 'sqlite']);

it('adds nullable node claim tokens on the configured database without changing legacy records', function () {
    $connection = 'node_ownership_upgrade';
    $table = 'custom_node_ownership_executions';
    $originalConnection = config('agent-graph.database.connection');
    $originalTable = config('agent-graph.tables.node_executions');

    try {
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
        $migrationDirectory = realpath(__DIR__.'/../../database/migrations');
        $oldMigrations = array_map(fn (string $name): string => $migrationDirectory.'/'.$name, [
            '2026_05_25_000000_create_agent_graph_tables.php',
            '2026_05_26_000000_add_agent_graph_hardening_tables.php',
            '2026_05_26_010000_add_worker_fields_to_agent_graph_node_executions.php',
            '2026_05_30_000000_add_agent_graph_runtime_invariants.php',
        ]);
        $this->artisan('migrate', ['--database' => $connection, '--path' => $oldMigrations, '--realpath' => true])->run();
        $database = DB::connection($connection);

        foreach (['pending', 'running', 'completed'] as $status) {
            $database->table($table)->insert([
                'execution_id' => 'legacy-'.$status,
                'run_id' => 'run-'.$status,
                'step' => 1,
                'node_id' => 'worker',
                'status' => $status,
                'writes' => json_encode($status === 'completed' ? ['answer' => 'historical result'] : [], JSON_THROW_ON_ERROR),
                'meta' => json_encode(['legacy' => true], JSON_THROW_ON_ERROR),
                'locked_until' => $status === 'running' ? now()->addMinute() : null,
                'finished_at' => $status === 'completed' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $legacyRows = $database->table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        $migration = $migrationDirectory.'/2026_08_31_010000_add_claim_token_to_agent_graph_node_executions.php';
        $this->artisan('migrate', ['--database' => $connection, '--path' => [$migration], '--realpath' => true])->run();
        $migratedRows = $database->table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

        expect(Schema::connection($connection)->hasColumn($table, 'claim_token'))->toBeTrue()
            ->and(Schema::connection(config('database.default'))->hasTable($table))->toBeFalse()
            ->and(array_column($migratedRows, 'claim_token'))->toBe([null, null, null]);
        $withoutTokens = array_map(function (array $row): array {
            unset($row['claim_token']);

            return $row;
        }, $migratedRows);
        expect($withoutTokens)->toEqual($legacyRows);

        $store = new DatabaseNodeExecutionStore(app('db'));
        $completed = $store->claim('legacy-completed', now()->addMinute());
        expect($completed['status'])->toBe('completed')
            ->and($completed['claim_token'])->toBeNull()
            ->and($completed['writes'])->toBe(['answer' => 'historical result'])
            ->and($store->claim('legacy-running', now()->addMinute()))->toBeNull();
        expect(fn () => $store->fail('legacy-running', '', ['message' => 'unowned']))
            ->toThrow(NodeExecutionClaimLostException::class);

        $pending = $store->claim('legacy-pending', now()->addMinute());
        expect(Str::isUlid($pending['claim_token']))->toBeTrue();
        $store->complete('legacy-pending', $pending['claim_token'], ['writes' => ['answer' => 'pending resumed']]);
        Carbon::setTestNow(now()->addSeconds(61));
        $running = $store->claim('legacy-running', now()->addMinute());
        expect(Str::isUlid($running['claim_token']))->toBeTrue();
        $store->complete('legacy-running', $running['claim_token'], ['writes' => ['answer' => 'expired worker reclaimed']]);

        $rowsBeforeRollback = $database->table($table)->orderBy('id')->get()->map(function (object $row): array {
            $values = (array) $row;
            unset($values['claim_token']);

            return $values;
        })->all();
        $this->artisan('migrate:rollback', ['--database' => $connection, '--path' => [$migration], '--realpath' => true, '--step' => 1])->run();
        expect(Schema::connection($connection)->hasColumn($table, 'claim_token'))->toBeFalse()
            ->and($database->table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())
            ->toEqual($rowsBeforeRollback);
    } finally {
        config([
            'agent-graph.database.connection' => $originalConnection,
            'agent-graph.tables.node_executions' => $originalTable,
        ]);
        DB::purge($connection);
    }
});

function nodeOwnershipStore(string $driver): NodeExecutionStore
{
    return $driver === 'sqlite'
        ? new DatabaseNodeExecutionStore(app('db'))
        : new InMemoryNodeExecutionStore;
}

function scheduleNodeOwnershipExecution(NodeExecutionStore $store): array
{
    return $store->schedule([
        'run_id' => 'node-ownership-run',
        'step' => 1,
        'node_id' => 'worker',
        'base_state' => ['answer' => 'initial'],
        'node_state' => ['answer' => 'initial'],
    ]);
}
