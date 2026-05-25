<?php

namespace Heiner\AgentGraph\Console;

use Illuminate\Console\GeneratorCommand;

class MakeGraphCommand extends GeneratorCommand
{
    protected $signature = 'agent-graph:make-graph {name}';

    protected $description = 'Create an AgentGraph graph definition class.';

    protected $type = 'AgentGraph graph';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/graph.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\AgentGraph\\Graphs';
    }
}
