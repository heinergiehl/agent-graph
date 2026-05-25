<?php

return [
    'store' => env('AGENT_GRAPH_STORE', 'database'),

    'max_steps' => env('AGENT_GRAPH_MAX_STEPS', 100),

    'tables' => [
        'runs' => 'agent_graph_runs',
        'checkpoints' => 'agent_graph_checkpoints',
        'writes' => 'agent_graph_writes',
        'tasks' => 'agent_graph_tasks',
        'interrupts' => 'agent_graph_interrupts',
        'memories' => 'agent_graph_memories',
        'traces' => 'agent_graph_traces',
    ],

    'memory' => [
        'fallback_order' => ['run', 'thread', 'actor', 'tenant', 'application', 'global'],
    ],

    'tracing' => [
        'enabled' => true,
        'record_state' => false,
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
