<?php

namespace Heiner\AgentGraph\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'agent-graph:install';

    protected $description = 'Publish AgentGraph configuration and migrations.';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'agent-graph-config']);
        $this->call('vendor:publish', ['--tag' => 'agent-graph-migrations']);

        $this->components->info('AgentGraph installation files published.');

        return self::SUCCESS;
    }
}
