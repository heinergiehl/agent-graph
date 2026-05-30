<?php

return [
    'store' => env('AGENT_GRAPH_STORE', 'database'),

    'max_steps' => env('AGENT_GRAPH_MAX_STEPS', 100),

    'max_parallel_nodes' => env('AGENT_GRAPH_MAX_PARALLEL_NODES', 50),

    'database' => [
        'connection' => env('AGENT_GRAPH_DB_CONNECTION'),
    ],

    'locks' => [
        'ttl_seconds' => env('AGENT_GRAPH_LOCK_TTL_SECONDS', 300),
        'block_seconds' => env('AGENT_GRAPH_LOCK_BLOCK_SECONDS', 5),
        'fail_without_provider' => env('AGENT_GRAPH_LOCK_FAIL_WITHOUT_PROVIDER', true),
    ],

    'tasks' => [
        'lease_seconds' => env('AGENT_GRAPH_TASK_LEASE_SECONDS', 300),
    ],

    'execution' => [
        'mode' => env('AGENT_GRAPH_EXECUTION_MODE', 'sync'),
        'queue_connection' => env('AGENT_GRAPH_EXECUTION_QUEUE_CONNECTION'),
        'queue' => env('AGENT_GRAPH_EXECUTION_QUEUE'),
        'node_lease_seconds' => env('AGENT_GRAPH_EXECUTION_NODE_LEASE_SECONDS', 300),
        'job_tries' => env('AGENT_GRAPH_JOB_TRIES', 3),
        'job_timeout' => env('AGENT_GRAPH_JOB_TIMEOUT', 300),
        'job_backoff' => array_map('intval', explode(',', (string) env('AGENT_GRAPH_JOB_BACKOFF', '5'))),
    ],

    'tables' => [
        'runs' => 'agent_graph_runs',
        'checkpoints' => 'agent_graph_checkpoints',
        'writes' => 'agent_graph_writes',
        'tasks' => 'agent_graph_tasks',
        'interrupts' => 'agent_graph_interrupts',
        'memories' => 'agent_graph_memories',
        'node_executions' => 'agent_graph_node_executions',
        'traces' => 'agent_graph_traces',
    ],

    'memory' => [
        'fallback_order' => ['run', 'thread', 'actor', 'tenant', 'application', 'global'],
    ],

    'vector_memory' => [
        'table' => env('AGENT_GRAPH_VECTOR_MEMORY_TABLE', 'agent_graph_vector_memories'),
    ],

    'tracing' => [
        'enabled' => true,
        'redact_keys' => [
            'password',
            'token',
            'secret',
            'api_key',
            'authorization',
            'cookie',
            'credit_card',
            'ssn',
        ],
        'max_string_length' => 2000,
        'max_payload_size' => 65535,
    ],
];
