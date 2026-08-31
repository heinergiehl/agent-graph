<?php

use Heiner\AgentGraph\Persistence\DatabaseRunStore;
use Heiner\AgentGraph\Persistence\InMemoryRunStore;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\PostgresConnection;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
});

it('finds older child and time travel runs after more than a thousand unrelated runs', function (string $driver) {
    $store = $driver === 'database' ? new DatabaseRunStore(app('db')) : new InMemoryRunStore;
    $parent = $store->create('parent', '1', 'parent-thread');
    $lineage = [
        'parent' => ['run_id' => $parent['public_id']],
        'time_travel' => ['source_checkpoint_id' => 'source-checkpoint'],
    ];
    $older = $store->create('child', '1', 'child-thread-1', [], $lineage);
    $newer = $store->create('child', '1', 'child-thread-2', [], $lineage);

    for ($index = 0; $index < 1001; $index++) {
        $store->create('unrelated', '1', 'unrelated-'.$index, [], [
            'parent' => ['run_id' => 'another-parent'],
            'time_travel' => ['source_checkpoint_id' => 'another-checkpoint'],
        ]);
    }

    expect(array_column($store->listChildRuns($parent['public_id']), 'public_id'))
        ->toBe([$newer['public_id'], $older['public_id']])
        ->and(array_column($store->listTimeTravelChildren('source-checkpoint'), 'public_id'))
        ->toBe([$newer['public_id'], $older['public_id']])
        ->and(array_column($store->listChildRuns($parent['public_id'], 1), 'public_id'))
        ->toBe([$newer['public_id']])
        ->and(array_column($store->listTimeTravelChildren('source-checkpoint', 1), 'public_id'))
        ->toBe([$newer['public_id']])
        ->and($store->listChildRuns('missing-parent'))->toBeEmpty()
        ->and($store->listTimeTravelChildren('missing-checkpoint'))->toBeEmpty();
})->with(['database', 'memory']);

it('compiles PostgreSQL lineage filters for the existing text metadata column', function () {
    $connection = new class(fn () => throw new RuntimeException('Grammar verification must not connect.'), 'unused', '', ['driver' => 'pgsql']) extends PostgresConnection
    {
        public array $queries = [];

        public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
        {
            $this->queries[] = ['query' => $query, 'bindings' => $bindings];

            return [];
        }
    };
    $store = new class(app('db'), $connection) extends DatabaseRunStore
    {
        public function __construct(DatabaseManager $db, private PostgresConnection $grammarConnection)
        {
            parent::__construct($db);
        }

        protected function connection(): PostgresConnection
        {
            return $this->grammarConnection;
        }
    };

    $store->listChildRuns('parent-id', 2);
    $store->listTimeTravelChildren('checkpoint-id', 3);
    $queries = $connection->queries;

    expect($queries)->toHaveCount(2)
        ->and($queries[0]['query'])->toContain('("meta"::jsonb #>> ?) = ?', 'limit 2')
        ->and($queries[0]['bindings'])->toBe(['{parent,run_id}', 'parent-id'])
        ->and($queries[1]['query'])->toContain('("meta"::jsonb #>> ?) = ?', 'limit 3')
        ->and($queries[1]['bindings'])->toBe(['{time_travel,source_checkpoint_id}', 'checkpoint-id']);
});
