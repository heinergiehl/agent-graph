<?php

use Heiner\AgentGraph\Contracts\LeasingTaskStore;
use Heiner\AgentGraph\Runtime\TaskRunner;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
});

it('leases idempotent tasks and rejects active duplicate execution', function () {
    config()->set('agent-graph.tasks.lease_seconds', 60);

    /** @var LeasingTaskStore $store */
    $store = app('agent-graph.tasks');
    $store->start('leased-task', hash('sha256', json_encode(['id' => 1], JSON_THROW_ON_ERROR)), ['id' => 1], [
        'run_id' => 'run_1',
        'node_id' => 'node_1',
    ]);

    $runner = new TaskRunner($store, 'run_2', 'node_2');

    expect(fn () => $runner->once('leased-task', ['id' => 1], fn () => 'duplicate'))
        ->toThrow(RuntimeException::class, 'is already running');
});

it('reclaims expired task leases and returns completed task results', function () {
    config()->set('agent-graph.tasks.lease_seconds', -1);

    /** @var LeasingTaskStore $store */
    $store = app('agent-graph.tasks');
    $runner = new TaskRunner($store, 'run_1', 'node_1');

    expect($runner->once('expired-task', ['id' => 1], fn () => 'first'))->toBe('first')
        ->and($runner->once('expired-task', ['id' => 1], fn () => 'second'))->toBe('first');
});
