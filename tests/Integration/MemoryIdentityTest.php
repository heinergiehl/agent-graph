<?php

use Heiner\AgentGraph\Memory\MemoryIdentity;
use Heiner\AgentGraph\Memory\MemoryScope;
use Heiner\AgentGraph\Persistence\DatabaseMemoryStore;
use Heiner\AgentGraph\Persistence\InMemoryMemoryStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('accepts memory identities at the portable limits and rejects longer parts on every store', function (string $driver) {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
    $store = $driver === 'database' ? new DatabaseMemoryStore(app('db')) : new InMemoryMemoryStore;
    $scope = new MemoryScope(str_repeat('t', 64), str_repeat('ü', 191), str_repeat('m', 128));

    $written = $store->write($scope, str_repeat('n', 128), str_repeat('k', 255), 'value');
    expect($written['value'])->toBe('value');

    foreach ([
        'scope type' => [new MemoryScope(str_repeat('t', 65), 'id'), 'ns', 'key'],
        'scope id' => [new MemoryScope('thread', str_repeat('ü', 192)), 'ns', 'key'],
        'tenant id' => [new MemoryScope('thread', 'id', str_repeat('m', 129)), 'ns', 'key'],
        'namespace' => [MemoryScope::thread('id'), str_repeat('n', 129), 'key'],
        'key' => [MemoryScope::thread('id'), 'ns', str_repeat('k', 256)],
    ] as $label => [$overlong, $namespace, $key]) {
        expect(fn () => $store->write($overlong, $namespace, $key, 'value'))
            ->toThrow(InvalidArgumentException::class, "AgentGraph memory {$label} must not exceed");
    }
})->with(['database', 'memory']);

it('enforces one record per identity including records without a tenant', function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
    $store = new DatabaseMemoryStore(app('db'));
    $scope = MemoryScope::global();
    $table = config('agent-graph.tables.memories');

    $first = $store->write($scope, 'facts', 'plan', 'basic');
    $second = $store->write($scope, 'facts', 'plan', 'pro');

    expect($second['id'])->toBe($first['id'])
        ->and(DB::table($table)->count())->toBe(1)
        ->and(DB::table($table)->value('identity_hash'))->toBe(MemoryIdentity::hash($scope, 'facts', 'plan'));

    expect(fn () => DB::table($table)->insert([
        'scope_type' => 'global',
        'scope_id' => 'global',
        'tenant_id' => null,
        'namespace' => 'facts',
        'key' => 'plan',
        'identity_hash' => MemoryIdentity::hash($scope, 'facts', 'plan'),
        'memory_type' => 'fact',
        'usage_count' => 0,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('updates the winning record when a concurrent writer inserted the identity first', function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
    $table = config('agent-graph.tables.memories');
    $scope = MemoryScope::thread('race-thread');
    $store = new class(app('db'), $table, $scope) extends DatabaseMemoryStore
    {
        public int $queries = 0;

        public function __construct($db, private string $memoryTable, private MemoryScope $scope)
        {
            parent::__construct($db);
        }

        protected function connection()
        {
            // The second connection use opens the insert after the existence
            // check found nothing; a competitor commits the identity first.
            if (++$this->queries === 2) {
                DB::table($this->memoryTable)->insert([
                    'scope_type' => $this->scope->type,
                    'scope_id' => $this->scope->id,
                    'tenant_id' => null,
                    'namespace' => 'facts',
                    'key' => 'plan',
                    'identity_hash' => MemoryIdentity::hash($this->scope, 'facts', 'plan'),
                    'memory_type' => 'fact',
                    'value' => json_encode('competitor'),
                    'usage_count' => 0,
                ]);
            }

            return parent::connection();
        }
    };

    DB::transaction(function () use ($store, $scope): void {
        $receipt = $store->write($scope, 'facts', 'plan', 'mine');

        expect($receipt['value'])->toBe('mine');
    });

    expect($store->queries)->toBeGreaterThan(2)
        ->and(DB::table($table)->count())->toBe(1)
        ->and(json_decode(DB::table($table)->value('value')))->toBe('mine');
});

it('backfills identity hashes without deleting legacy duplicates and is idempotent', function () {
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $path) {
        if (! str_contains($path, 'add_identity_hash_to_agent_graph_memories')) {
            (require $path)->up();
        }
    }

    $table = config('agent-graph.tables.memories');
    expect(Schema::hasColumn($table, 'identity_hash'))->toBeFalse();

    $row = fn (string $key, ?string $tenant, string $value): array => [
        'scope_type' => 'thread',
        'scope_id' => 'legacy',
        'tenant_id' => $tenant,
        'namespace' => 'facts',
        'key' => $key,
        'memory_type' => 'fact',
        'value' => json_encode($value),
        'usage_count' => 0,
    ];
    DB::table($table)->insert($row('plan', null, 'oldest'));
    DB::table($table)->insert($row('plan', null, 'newer duplicate'));
    DB::table($table)->insert($row('plan', 'tenant', 'tenant scoped'));
    DB::table($table)->insert($row('region', null, 'single'));

    $migration = require __DIR__.'/../../database/migrations/2026_10_14_000000_add_identity_hash_to_agent_graph_memories.php';
    $migration->up();
    $migration->up();

    $records = DB::table($table)->orderBy('id')->get();
    expect($records)->toHaveCount(4)
        ->and($records[0]->identity_hash)->toBe(MemoryIdentity::hash(MemoryScope::thread('legacy'), 'facts', 'plan'))
        ->and($records[1]->identity_hash)->toBeNull()
        ->and($records[2]->identity_hash)->toBe(MemoryIdentity::hash(MemoryScope::thread('legacy', 'tenant'), 'facts', 'plan'))
        ->and($records[3]->identity_hash)->toBe(MemoryIdentity::hash(MemoryScope::thread('legacy'), 'facts', 'region'))
        ->and(Schema::hasIndex($table, 'agent_graph_memory_identity_unique', 'unique'))->toBeTrue();

    $store = new DatabaseMemoryStore(app('db'));
    $store->write(MemoryScope::thread('legacy'), 'facts', 'plan', 'updated');
    expect(DB::table($table)->where('key', 'plan')->whereNull('tenant_id')->pluck('value')->map(fn ($value) => json_decode($value))->all())
        ->toBe(['updated', 'updated']);
});

it('distinguishes a null tenant from an empty tenant and is unambiguous for separators', function () {
    expect(MemoryIdentity::hash(new MemoryScope('thread', 'a', null), 'n', 'k'))
        ->not->toBe(MemoryIdentity::hash(new MemoryScope('thread', 'a', ''), 'n', 'k'))
        ->and(MemoryIdentity::hash(new MemoryScope('thread', 'a|b', null), 'n', 'k'))
        ->not->toBe(MemoryIdentity::hash(new MemoryScope('thread', 'a', null), 'b|n', 'k'));
});
