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

        $this->line('Store driver: '.config('agent-graph.store'));
        $this->line('Database connection: '.AgentGraphDatabase::displayConnectionName());
        $this->line('Queue connection: '.config('queue.default'));
        $this->line('Cache driver: '.config('cache.default'));
        $this->line('Cache locks: '.($this->supportsCacheLocks() ? 'available' : 'unavailable'));

        $schema = $this->schema();

        foreach (config('agent-graph.tables') as $name => $table) {
            $present = $schema->hasTable($table);
            $failed = $failed || ! $present;
            $this->line(sprintf('%s table [%s]: %s', $name, $table, $present ? 'present' : 'missing'));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
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
}
