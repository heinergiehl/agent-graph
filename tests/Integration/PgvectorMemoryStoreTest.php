<?php

use Heiner\AgentGraph\Memory\MemoryScope;
use Heiner\AgentGraph\Memory\PgvectorMemoryStore;
use Illuminate\Support\Facades\DB;

it('stores and searches vector memories with pgvector when explicitly enabled', function () {
    if (env('AGENT_GRAPH_PGVECTOR_TEST') !== '1') {
        $this->markTestSkipped('Set AGENT_GRAPH_PGVECTOR_TEST=1 to run pgvector integration tests.');
    }

    config()->set('database.default', 'agent_graph_pgvector');
    config()->set('database.connections.agent_graph_pgvector', [
        'driver' => 'pgsql',
        'host' => env('AGENT_GRAPH_PGVECTOR_HOST', '127.0.0.1'),
        'port' => env('AGENT_GRAPH_PGVECTOR_PORT', '55436'),
        'database' => env('AGENT_GRAPH_PGVECTOR_DATABASE', 'filament_agentic_chatbot'),
        'username' => env('AGENT_GRAPH_PGVECTOR_USERNAME', 'postgres'),
        'password' => env('AGENT_GRAPH_PGVECTOR_PASSWORD', 'postgres'),
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => 'public',
    ]);
    config()->set('agent-graph.vector_memory.table', 'agent_graph_vector_memory_test');

    DB::purge('agent_graph_pgvector');
    DB::reconnect('agent_graph_pgvector');
    DB::statement('create extension if not exists vector');
    DB::statement('create table if not exists agent_graph_vector_memory_test (
        id bigserial primary key,
        scope_type varchar(255) not null,
        scope_id varchar(255) not null,
        tenant_id varchar(255) null,
        namespace varchar(255) not null,
        key varchar(255) not null,
        embedding vector,
        memory jsonb not null,
        created_at timestamp null,
        updated_at timestamp null
    )');

    $namespace = 'pgvector-test-'.str()->ulid();
    $store = new PgvectorMemoryStore(app('db'));
    $scope = MemoryScope::thread('thread-pgvector', 'tenant-pgvector');

    try {
        $store->upsert($scope, $namespace, 'near', [1.0, 0.0, 0.0], ['value' => 'near']);
        $store->upsert($scope, $namespace, 'far', [0.0, 1.0, 0.0], ['value' => 'far']);

        $results = $store->search([$scope], $namespace, [0.95, 0.05, 0.0], limit: 1);

        expect($results)->toHaveCount(1)
            ->and($results[0]['key'])->toBe('near')
            ->and($results[0]['memory'])->toBe(['value' => 'near']);
    } finally {
        DB::table('agent_graph_vector_memory_test')->where('namespace', $namespace)->delete();
    }
})->group('pgvector');
