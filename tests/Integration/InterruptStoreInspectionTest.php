<?php

use Heiner\AgentGraph\Persistence\DatabaseCheckpointStore;
use Heiner\AgentGraph\Persistence\DatabaseInterruptStore;
use Heiner\AgentGraph\Persistence\DatabaseRunStore;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
});

it('lists interrupts for a run and resolves the latest interrupt for projection reads', function () {
    $runs = new DatabaseRunStore(app('db'));
    $checkpoints = new DatabaseCheckpointStore(app('db'));
    $interrupts = new DatabaseInterruptStore(app('db'));

    $run = $runs->create('projection_graph', '1', 'thread-projection');
    $checkpoint = $checkpoints->create([
        'run_id' => $run['public_id'],
        'thread_id' => $run['thread_id'],
        'graph_key' => $run['graph_key'],
        'graph_version' => $run['graph_version'],
        'step' => 1,
        'state' => [],
        'next_nodes' => ['ask'],
        'completed_nodes' => ['ask'],
        'interrupts' => [],
        'meta' => [],
    ]);

    $first = $interrupts->create([
        'run_id' => $run['public_id'],
        'checkpoint_id' => $checkpoint['checkpoint_id'],
        'node_id' => 'ask',
        'type' => 'input',
        'payload' => ['prompt' => 'First'],
    ]);
    $second = $interrupts->create([
        'run_id' => $run['public_id'],
        'checkpoint_id' => $checkpoint['checkpoint_id'],
        'node_id' => 'ask',
        'type' => 'approval',
        'payload' => ['prompt' => 'Second'],
    ]);

    $interrupts->resolve($second['interrupt_id'], ['answer' => 'yes']);

    expect($interrupts->listForRun($run['public_id']))->toHaveCount(2)
        ->and($interrupts->latestForRun($run['public_id'])['interrupt_id'])->toBe($second['interrupt_id'])
        ->and($interrupts->latestForRun($run['public_id'])['response'])->toBe(['answer' => 'yes'])
        ->and($interrupts->find($first['interrupt_id'])['status'])->toBe('pending');
});
