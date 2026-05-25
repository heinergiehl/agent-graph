<?php

it('redacts sensitive trace payload keys and truncates large strings', function () {
    $trace = app('agent-graph.traces')->record('run_redact', 'test.event', [
        'api_key' => 'secret-value',
        'nested' => ['token' => 'nested-secret'],
        'message' => str_repeat('a', 2505),
    ]);

    expect($trace['payload']['api_key'])->toBe('[redacted]')
        ->and($trace['payload']['nested']['token'])->toBe('[redacted]')
        ->and(strlen($trace['payload']['message']))->toBe(2000);
});

it('caps trace payload size', function () {
    config()->set('agent-graph.tracing.max_payload_size', 100);

    $trace = app('agent-graph.traces')->record('run_payload_cap', 'test.event', [
        'message' => str_repeat('x', 500),
        'safe' => 'value',
    ]);

    expect($trace['payload']['_truncated'])->toBeTrue()
        ->and($trace['payload']['_original_size'])->toBeGreaterThan(100)
        ->and(strlen($trace['payload']['preview']))->toBe(100);
});
