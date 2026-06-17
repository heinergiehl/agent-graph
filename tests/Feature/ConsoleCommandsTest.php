<?php

use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('doctor reports missing tables and infrastructure settings', function () {
    $this->artisan('agent-graph:doctor')
        ->expectsOutputToContain('Store driver:')
        ->expectsOutputToContain('Database connection:')
        ->expectsOutputToContain('Cache locks:')
        ->expectsOutputToContain('Lock fail-closed:')
        ->expectsOutputToContain('Execution mode:')
        ->expectsOutputToContain('Queue connection:')
        ->expectsOutputToContain('Queue name:')
        ->expectsOutputToContain('Cache driver:')
        ->expectsOutputToContain('Task lease seconds:')
        ->expectsOutputToContain('Node lease seconds:')
        ->expectsOutputToContain('Max steps:')
        ->expectsOutputToContain('runs table')
        ->assertFailed();
});

it('doctor passes when package tables exist', function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();

    $this->artisan('agent-graph:doctor')
        ->expectsOutputToContain('PASS Store driver: database')
        ->expectsOutputToContain('PASS Cache locks: available')
        ->expectsOutputToContain('runs table')
        ->expectsOutputToContain('present')
        ->assertSuccessful();
});

it('doctor fails unsafe production settings', function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    Artisan::call('migrate', ['--force' => true]);

    $this->app->detectEnvironment(fn () => 'production');
    config()->set('agent-graph.store', 'memory');
    config()->set('agent-graph.locks.fail_without_provider', false);
    config()->set('agent-graph.locks.ttl_seconds', 10);
    config()->set('agent-graph.execution.node_lease_seconds', 20);
    config()->set('agent-graph.tasks.lease_seconds', 0);
    config()->set('agent-graph.max_steps', 0);

    try {
        $exitCode = Artisan::call('agent-graph:doctor');
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('FAIL Store driver: memory')
            ->and($output)->toContain('FAIL Lock fail-closed: disabled outside local/testing')
            ->and($output)->toContain('FAIL Task lease seconds: 0')
            ->and($output)->toContain('FAIL Max steps: 0')
            ->and($output)->toContain('FAIL Lock TTL seconds: 10 is lower than node lease seconds 20');
    } finally {
        $this->app->detectEnvironment(fn () => 'testing');
    }
});

it('validates registered graph definitions from the console', function () {
    AgentGraph::define(
        StateGraph::make('valid_console_graph')
            ->state(['message' => 'string'])
            ->node('answer', ConsoleValidationAnswerNode::class)
            ->edge(StateGraph::START, 'answer')
            ->compile(),
    );

    AgentGraph::define(
        StateGraph::make('invalid_console_graph')
            ->state(['message' => 'strng'])
            ->node('answer', ConsoleValidationAnswerNode::class)
            ->node('orphan', ConsoleValidationAnswerNode::class)
            ->edge(StateGraph::START, 'answer')
            ->compile(),
    );

    $this->artisan('agent-graph:validate valid_console_graph')
        ->expectsOutputToContain('PASS Graph [valid_console_graph] passed validation')
        ->assertSuccessful();

    $this->artisan('agent-graph:validate invalid_console_graph')
        ->expectsOutputToContain('FAIL Graph [invalid_console_graph] validation failed')
        ->expectsOutputToContain('unknown_state_schema_type')
        ->expectsOutputToContain('unreachable_node')
        ->assertFailed();
});

it('fails graph validation when no graphs are registered unless explicitly allowed', function () {
    $this->artisan('agent-graph:validate')
        ->expectsOutputToContain('FAIL No AgentGraph definitions are registered in this process.')
        ->assertFailed();

    $this->artisan('agent-graph:validate --allow-empty')
        ->expectsOutputToContain('WARN No AgentGraph definitions are registered in this process.')
        ->assertSuccessful();
});

final class ConsoleValidationAnswerNode
{
    public function __invoke(): array
    {
        return [];
    }
}

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
