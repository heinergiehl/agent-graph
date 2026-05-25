<?php

namespace Heiner\AgentGraph\Console;

use Illuminate\Console\GeneratorCommand;

class MakeNodeCommand extends GeneratorCommand
{
    protected $signature = 'agent-graph:make-node {name}';

    protected $description = 'Create an AgentGraph node class.';

    protected $type = 'AgentGraph node';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/node.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\AgentGraph\\Nodes';
    }
}
