<?php

namespace Heiner\AgentGraph\Console;

use Heiner\AgentGraph\AgentGraphManager;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ValidateCommand extends Command
{
    protected $signature = 'agent-graph:validate
        {graph? : Registered graph key to validate}
        {--allow-empty : Return success when no graph definitions are registered}
        {--strict : Treat warnings as validation failures}
        {--json : Output a machine-readable JSON report}';

    protected $description = 'Validate registered AgentGraph definitions for release readiness.';

    public function handle(AgentGraphManager $manager): int
    {
        $graph = $this->argument('graph');
        $strict = (bool) $this->option('strict');
        $json = (bool) $this->option('json');
        $definitions = $manager->definitions();

        if (is_string($graph) && $graph !== '') {
            try {
                $definitions = [$graph => $manager->definition($graph)];
            } catch (InvalidArgumentException $exception) {
                if ($json) {
                    $this->writeJson([
                        'passed' => false,
                        'failed' => true,
                        'strict' => $strict,
                        'graph_count' => 0,
                        'errors' => [[
                            'severity' => 'error',
                            'code' => 'unknown_graph',
                            'message' => $exception->getMessage(),
                            'graph' => $graph,
                        ]],
                        'graphs' => [],
                    ]);

                    return self::FAILURE;
                }

                $this->error('FAIL '.$exception->getMessage());

                return self::FAILURE;
            }
        }

        if ($definitions === []) {
            $allowed = (bool) $this->option('allow-empty');
            $passed = $allowed && ! $strict;

            if ($json) {
                $this->writeJson([
                    'passed' => $passed,
                    'failed' => ! $passed,
                    'strict' => $strict,
                    'graph_count' => 0,
                    'errors' => $allowed ? [] : [[
                        'severity' => 'error',
                        'code' => 'empty_graph_registry',
                        'message' => 'No AgentGraph definitions are registered in this process.',
                    ]],
                    'warnings' => $allowed ? [[
                        'severity' => 'warning',
                        'code' => 'empty_graph_registry',
                        'message' => 'No AgentGraph definitions are registered in this process.',
                    ]] : [],
                    'graphs' => [],
                ]);

                return $passed ? self::SUCCESS : self::FAILURE;
            }

            if ($this->option('allow-empty')) {
                $this->warn('WARN No AgentGraph definitions are registered in this process.');

                return $strict ? self::FAILURE : self::SUCCESS;
            }

            $this->error('FAIL No AgentGraph definitions are registered in this process.');

            return self::FAILURE;
        }

        $failed = false;
        $reports = [];

        foreach ($definitions as $key => $definition) {
            $report = $manager->validate((string) $key);
            $reports[(string) $key] = array_merge([
                'key' => (string) $key,
                'version' => $definition->version(),
            ], $report->toArray($strict));

            if ($report->failed($strict)) {
                $failed = true;
                if (! $json) {
                    $this->error("FAIL Graph [{$key}] validation failed");
                }
            } elseif (! $json) {
                $this->info("PASS Graph [{$key}] passed validation");
            }

            if (! $json) {
                foreach ($report->errors() as $error) {
                    $this->error('  ERROR '.$error['code'].': '.$error['message']);
                }

                foreach ($report->warnings() as $warning) {
                    $this->warn('  WARN '.$warning['code'].': '.$warning['message']);
                }
            }
        }

        if ($json) {
            $this->writeJson([
                'passed' => ! $failed,
                'failed' => $failed,
                'strict' => $strict,
                'graph_count' => count($reports),
                'graphs' => $reports,
            ]);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    protected function writeJson(array $payload): void
    {
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
