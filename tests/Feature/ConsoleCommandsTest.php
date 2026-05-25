<?php

use Illuminate\Support\Facades\DB;

it('doctor reports missing tables and infrastructure settings', function () {
    $this->artisan('agent-graph:doctor')
        ->expectsOutputToContain('Store driver:')
        ->expectsOutputToContain('Queue connection:')
        ->expectsOutputToContain('Cache driver:')
        ->expectsOutputToContain('runs table')
        ->assertFailed();
});

it('doctor passes when package tables exist', function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();

    $this->artisan('agent-graph:doctor')
        ->expectsOutputToContain('runs table')
        ->expectsOutputToContain('present')
        ->assertSuccessful();
});

it('prunes only selected old data and expired memories', function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();

    $traces = config('agent-graph.tables.traces');
    $tasks = config('agent-graph.tables.tasks');
    $memories = config('agent-graph.tables.memories');
    $old = now()->subDays(45);
    $fresh = now();

    DB::table($traces)->insert([
        ['run_id' => 'run_old', 'event' => 'old', 'payload' => null, 'meta' => null, 'created_at' => $old, 'updated_at' => $old],
        ['run_id' => 'run_fresh', 'event' => 'fresh', 'payload' => null, 'meta' => null, 'created_at' => $fresh, 'updated_at' => $fresh],
    ]);

    DB::table($tasks)->insert([
        ['task_key' => 'old-task', 'status' => 'completed', 'input_hash' => 'hash-old', 'input' => '{}', 'attempts' => 1, 'created_at' => $old, 'updated_at' => $old],
        ['task_key' => 'fresh-task', 'status' => 'completed', 'input_hash' => 'hash-fresh', 'input' => '{}', 'attempts' => 1, 'created_at' => $fresh, 'updated_at' => $fresh],
    ]);

    DB::table($memories)->insert([
        [
            'scope_type' => 'actor',
            'scope_id' => 'user-old',
            'tenant_id' => 'tenant',
            'namespace' => 'preferences',
            'key' => 'expired',
            'memory_type' => 'preference',
            'value' => '"old"',
            'content' => 'Old preference',
            'expires_at' => now()->subMinute(),
            'usage_count' => 0,
            'created_at' => $old,
            'updated_at' => $old,
        ],
        [
            'scope_type' => 'actor',
            'scope_id' => 'user-fresh',
            'tenant_id' => 'tenant',
            'namespace' => 'preferences',
            'key' => 'fresh',
            'memory_type' => 'preference',
            'value' => '"fresh"',
            'content' => 'Fresh preference',
            'expires_at' => now()->addDay(),
            'usage_count' => 0,
            'created_at' => $fresh,
            'updated_at' => $fresh,
        ],
    ]);

    $this->artisan('agent-graph:prune --traces --tasks --memories --days=30')
        ->expectsOutputToContain('traces pruned: 1')
        ->expectsOutputToContain('tasks pruned: 1')
        ->expectsOutputToContain('expired memories pruned: 1')
        ->assertSuccessful();

    expect(DB::table($traces)->where('event', 'old')->exists())->toBeFalse()
        ->and(DB::table($traces)->where('event', 'fresh')->exists())->toBeTrue()
        ->and(DB::table($tasks)->where('task_key', 'old-task')->exists())->toBeFalse()
        ->and(DB::table($tasks)->where('task_key', 'fresh-task')->exists())->toBeTrue()
        ->and(DB::table($memories)->where('key', 'expired')->exists())->toBeFalse()
        ->and(DB::table($memories)->where('key', 'fresh')->exists())->toBeTrue();
});
