<?php

use Heiner\AgentGraph\Contracts\EnumerableMemoryStore;
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
    $childRun = $runs->create('support_triage', '1', 'thread-db', ['input' => 'Replay'], [
        'time_travel' => [
            'mode' => 'replay',
            'source_run_id' => $run['public_id'],
            'source_checkpoint_id' => 'chk_source',
        ],
    ]);

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

    $actorScope = MemoryScope::actor('tenant-db', 'user-db');
    $threadScope = MemoryScope::thread('thread-db');

    config()->set('agent-graph.memory.fallback_order', ['actor', 'thread']);

    $memory->write($threadScope, 'preferences', 'language', 'en', 'preference', 'Thread-level preference.');
    $memory->write($actorScope, 'preferences', 'language', 'de', 'preference', 'Prefers German.');

    expect($runs->find($run['public_id'])['status'])->toBe('running')
        ->and($runs->list(['thread_id' => 'thread-db']))->toHaveCount(2)
        ->and($runs->listTimeTravelChildren('chk_source'))->toHaveCount(1)
        ->and($runs->listTimeTravelChildren('chk_source')[0]['public_id'])->toBe($childRun['public_id'])
        ->and($checkpoints->find($checkpoint['checkpoint_id'])['state'])->toBe(['answer' => 'Hello'])
        ->and($checkpoints->latestForRun($run['public_id'])['state'])->toBe(['answer' => 'Hello'])
        ->and($interrupts->listForRun($run['public_id']))->toHaveCount(1)
        ->and($writes->listForCheckpoint($checkpoint['checkpoint_id']))->toHaveCount(1)
        ->and($writes->listForRun($run['public_id']))->toHaveCount(1)
        ->and($tasks->findByKey('task-key')['result'])->toBe(['ok' => true])
        ->and($memory)->toBeInstanceOf(EnumerableMemoryStore::class)
        ->and($memory->read([$actorScope], 'preferences', 'language')['value'])->toBe('de')
        ->and(array_map(fn (array $record): string => $record['value'], $memory->listNamespace([$threadScope, $actorScope], 'preferences')))
        ->toBe(['de', 'en']);
});
