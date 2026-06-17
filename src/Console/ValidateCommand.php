<?php

namespace Heiner\AgentGraph\Console;

use Heiner\AgentGraph\AgentGraphManager;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ValidateCommand extends Command
{
    protected $signature = 'agent-graph:validate
        {graph? : Registered graph key to validate}
        {--allow-empty : Return success when no graph definitions are registered}';

    protected $description = 'Validate registered AgentGraph definitions for release readiness.';

    public function handle(AgentGraphManager $manager): int
    {
        $graph = $this->argument('graph');
        $definitions = $manager->definitions();

        if (is_string($graph) && $graph !== '') {
            try {
                $definitions = [$graph => $manager->definition($graph)];
            } catch (InvalidArgumentException $exception) {
                $this->error('FAIL '.$exception->getMessage());

                return self::FAILURE;
            }
        }

        if ($definitions === []) {
            if ($this->option('allow-empty')) {
                $this->warn('WARN No AgentGraph definitions are registered in this process.');

                return self::SUCCESS;
            }

            $this->error('FAIL No AgentGraph definitions are registered in this process.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($definitions as $key => $definition) {
            $report = $manager->validate((string) $key);

            if ($report->failed()) {
                $failed = true;
                $this->error("FAIL Graph [{$key}] validation failed");
            } else {
                $this->info("PASS Graph [{$key}] passed validation");
            }

            foreach ($report->errors() as $error) {
                $this->error('  ERROR '.$error['code'].': '.$error['message']);
            }

            foreach ($report->warnings() as $warning) {
                $this->warn('  WARN '.$warning['code'].': '.$warning['message']);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
