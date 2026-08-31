<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    $this->artisan('migrate')->run();
});

it('reports a missing node ownership migration before workers start', function () {
    Schema::table(config('agent-graph.tables.node_executions'), function (Blueprint $table) {
        $table->dropColumn('claim_token');
    });

    try {
        $this->artisan('agent-graph:doctor')
            ->expectsOutputToContain('FAIL Node execution claim_token column: missing')
            ->assertFailed();
    } finally {
        Schema::table(config('agent-graph.tables.node_executions'), function (Blueprint $table) {
            $table->string('claim_token', 26)->nullable();
        });
    }
});

it('accepts the migrated node ownership schema', function () {
    $this->artisan('agent-graph:doctor')
        ->expectsOutputToContain('PASS Node execution claim_token column: present')
        ->assertSuccessful();
});
