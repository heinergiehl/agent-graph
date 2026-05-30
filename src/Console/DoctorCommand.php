<?php

namespace Heiner\AgentGraph\Console;

use Heiner\AgentGraph\Support\AgentGraphDatabase;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'agent-graph:doctor';

    protected $description = 'Inspect AgentGraph package configuration.';

    public function handle(): int
    {
        $failed = false;

        $supportsLocks = $this->supportsCacheLocks();
        $failClosed = (bool) config('agent-graph.locks.fail_without_provider', true);
        $store = (string) config('agent-graph.store', 'database');
        $executionMode = (string) config('agent-graph.execution.mode', 'sync');
        $taskLeaseSeconds = (int) config('agent-graph.tasks.lease_seconds', 300);
        $nodeLeaseSeconds = (int) config('agent-graph.execution.node_lease_seconds', 300);
        $lockTtlSeconds = (int) config('agent-graph.locks.ttl_seconds', 300);
        $maxSteps = (int) config('agent-graph.max_steps', 100);
        $productionLike = ! app()->environment(['local', 'testing']);

        if (! in_array($store, ['database', 'memory'], true)) {
            $failed = true;
            $this->failStatus('Store driver: '.$store.' is unsupported');
        } elseif ($productionLike && $store !== 'database') {
            $failed = true;
            $this->failStatus('Store driver: '.$store.' (database required outside local/testing)');
        } elseif ($store === 'database') {
            $this->pass('Store driver: database');
        } else {
            $this->warnStatus('Store driver: memory (local/testing only)');
        }

        $this->pass('Database connection: '.AgentGraphDatabase::displayConnectionName());
        $this->pass('Cache driver: '.config('cache.default'));

        if (! $supportsLocks && $failClosed) {
            $failed = true;
            $this->failStatus('Cache locks: unavailable while fail_without_provider=true');
        } elseif (! $supportsLocks) {
            $this->warnStatus('Cache locks: unavailable and fail_without_provider=false');
        } else {
            $this->pass('Cache locks: available');
        }

        if (! $failClosed && $productionLike) {
            $failed = true;
            $this->failStatus('Lock fail-closed: disabled outside local/testing');
        } elseif (! $failClosed) {
            $this->warnStatus('Lock fail-closed: disabled for local/testing');
        } else {
            $this->pass('Lock fail-closed: enabled');
        }

        if (! in_array($executionMode, ['sync', 'queued_supersteps'], true)) {
            $failed = true;
            $this->failStatus('Execution mode: '.$executionMode.' is unsupported');
        } else {
            $this->pass('Execution mode: '.$executionMode);
        }

        $queueConnection = $this->queueConnectionLabel();

        if ($executionMode === 'queued_supersteps' && $queueConnection === null) {
            $failed = true;
            $this->failStatus('Queue connection: missing for queued_supersteps');
        } else {
            $this->pass('Queue connection: '.($queueConnection ?? 'default'));
        }

        $this->pass('Queue name: '.$this->queueNameLabel());

        if ($taskLeaseSeconds <= 0) {
            $failed = true;
            $this->failStatus('Task lease seconds: '.$taskLeaseSeconds);
        } else {
            $this->pass('Task lease seconds: '.$taskLeaseSeconds);
        }

        if ($nodeLeaseSeconds <= 0) {
            $failed = true;
            $this->failStatus('Node lease seconds: '.$nodeLeaseSeconds);
        } else {
            $this->pass('Node lease seconds: '.$nodeLeaseSeconds);
        }

        if ($lockTtlSeconds <= 0) {
            $failed = true;
            $this->failStatus('Lock TTL seconds: '.$lockTtlSeconds);
        } elseif ($lockTtlSeconds < $nodeLeaseSeconds) {
            $failed = true;
            $this->failStatus("Lock TTL seconds: {$lockTtlSeconds} is lower than node lease seconds {$nodeLeaseSeconds}");
        } else {
            $this->pass('Lock TTL seconds: '.$lockTtlSeconds);
        }

        if ($maxSteps <= 0) {
            $failed = true;
            $this->failStatus('Max steps: '.$maxSteps);
        } else {
            $this->pass('Max steps: '.$maxSteps);
        }

        $schema = $this->schema();

        $tables = [];

        foreach (config('agent-graph.tables') as $name => $table) {
            $present = $schema->hasTable($table);
            $tables[$name] = $present;
            $failed = $failed || ! $present;
            $message = sprintf('%s table [%s]: %s', $name, $table, $present ? 'present' : 'missing');

            $present ? $this->pass($message) : $this->failStatus($message);
        }

        if ($executionMode === 'queued_supersteps' && ! ($tables['node_executions'] ?? false)) {
            $failed = true;
            $this->failStatus('Queued supersteps require the node_executions table.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    protected function pass(string $message): void
    {
        $this->info('PASS '.$message);
    }

    protected function warnStatus(string $message): void
    {
        $this->warn('WARN '.$message);
    }

    protected function failStatus(string $message): void
    {
        $this->error('FAIL '.$message);
    }

    protected function supportsCacheLocks(): bool
    {
        try {
            $lock = Cache::lock('agent-graph:doctor', 1);

            if ($lock->get()) {
                $lock->release();
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    protected function schema(): Builder
    {
        return Schema::connection(AgentGraphDatabase::connectionName());
    }

    protected function queueConnectionLabel(): ?string
    {
        $connection = config('agent-graph.execution.queue_connection');

        if (is_string($connection) && $connection !== '') {
            return $connection;
        }

        $default = config('queue.default');

        return is_string($default) && $default !== '' ? 'default ('.$default.')' : null;
    }

    protected function queueNameLabel(): string
    {
        $queue = config('agent-graph.execution.queue');

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }
}
