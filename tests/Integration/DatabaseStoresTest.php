<?php

use Heiner\AgentGraph\Memory\MemoryScope;
use Heiner\AgentGraph\Persistence\DatabaseCheckpointStore;
use Heiner\AgentGraph\Persistence\DatabaseInterruptStore;
use Heiner\AgentGraph\Persistence\DatabaseMemoryStore;
use Heiner\AgentGraph\Persistence\DatabaseRunStore;
use Heiner\AgentGraph\Persistence\DatabaseTaskStore;
use Heiner\AgentGraph\Persistence\DatabaseWriteStore;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
});

it('persists runs checkpoints writes tasks and memory in the database', function () {
    $runs = new DatabaseRunStore(app('db'));
    $checkpoints = new DatabaseCheckpointStore(app('db'));
    $interrupts = new DatabaseInterruptStore(app('db'));
    $writes = new DatabaseWriteStore(app('db'));
    $tasks = new DatabaseTaskStore(app('db'));
    $memory = new DatabaseMemoryStore(app('db'));

    $run = $runs->create('support_triage', '1', 'thread-db', ['input' => 'Hi'], ['tenant' => 'acme']);
    $run = $runs->update($run['public_id'], ['status' => 'running']);

    $checkpoint = $checkpoints->create([
        'run_id' => $run['public_id'],
        'thread_id' => 'thread-db',
        'graph_key' => 'support_triage',
        'graph_version' => '1',
        'step' => 1,
        'state' => ['answer' => 'Hello'],
        'next_nodes' => ['__end__'],
        'completed_nodes' => ['answer'],
        'interrupts' => [],
        'meta' => ['source' => 'test'],
    ]);

    $writes->createMany($run['public_id'], $checkpoint['checkpoint_id'], 'answer', ['answer' => 'Hello']);
    $interrupts->create([
        'run_id' => $run['public_id'],
        'checkpoint_id' => $checkpoint['checkpoint_id'],
        'node_id' => 'answer',
        'type' => 'input',
        'payload' => ['prompt' => 'Continue?'],
    ]);

    $task = $tasks->start('task-key', 'hash', ['input' => true], ['run_id' => $run['public_id']]);
    $tasks->complete('task-key', ['ok' => true]);

    $memory->write(MemoryScope::actor('tenant-db', 'user-db'), 'preferences', 'language', 'de', 'preference', 'Prefers German.');

    expect($runs->find($run['public_id'])['status'])->toBe('running')
        ->and($runs->list(['thread_id' => 'thread-db']))->toHaveCount(1)
        ->and($checkpoints->latestForRun($run['public_id'])['state'])->toBe(['answer' => 'Hello'])
        ->and($interrupts->listForRun($run['public_id']))->toHaveCount(1)
        ->and($writes->listForRun($run['public_id']))->toHaveCount(1)
        ->and($tasks->findByKey('task-key')['result'])->toBe(['ok' => true])
        ->and($memory->read([MemoryScope::actor('tenant-db', 'user-db')], 'preferences', 'language')['value'])->toBe('de');
});
