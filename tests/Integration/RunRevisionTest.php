<?php

use Heiner\AgentGraph\Exceptions\RunStateChangedException;
use Heiner\AgentGraph\Persistence\DatabaseRunStore;
use Heiner\AgentGraph\Persistence\InMemoryRunStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('rejects stale state transitions without altering the current owner', function (string $driver) {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->assertSuccessful();
    $store = $driver === 'memory' ? new InMemoryRunStore : new DatabaseRunStore(app('db'));
    $original = $store->create('revision', '1', 'revision-thread');
    $winner = $store->transition($original['public_id'], 0, ['status' => 'cancelled']);
    expect(fn () => $store->transition($original['public_id'], 0, ['status' => 'completed']))
        ->toThrow(RunStateChangedException::class);
    expect($store->find($original['public_id']))->toBe($winner)->and((int) $winner['revision'])->toBe(1);
})->with(['memory', 'database']);

it('upgrades a waiting legacy run without changing its identity or authority', function () {
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $path) {
        if (! str_contains($path, 'add_revision_to_agent_graph_runs')) {
            (require $path)->up();
        }
    }
    $runs = config('agent-graph.tables.runs');
    expect(Schema::hasColumn($runs, 'revision'))->toBeFalse();
    $store = new DatabaseRunStore(app('db'));
    $run = $store->create('upgrade', '1', 'waiting-upgrade', [], ['binding' => 'preserve']);
    DB::table($runs)->where('public_id', $run['public_id'])->update(['status' => 'interrupted', 'resume_at' => '2030-01-01 00:00:00']);
    $legacy = (array) DB::table($runs)->where('public_id', $run['public_id'])->first();
    $migration = require __DIR__.'/../../database/migrations/2026_09_12_000000_add_revision_to_agent_graph_runs.php';
    $migration->up();
    $migration->up();
    $upgraded = (array) DB::table($runs)->where('public_id', $run['public_id'])->first();
    expect(array_diff_key($upgraded, ['revision' => true]))->toBe($legacy)
        ->and((int) $upgraded['revision'])->toBe(0);
    $current = $store->transition($run['public_id'], 0, ['status' => 'running']);
    $migration->down();
    expect($store->find($run['public_id']))->toBe($current);
});
