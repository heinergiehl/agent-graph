<?php

namespace Heiner\AgentGraph\Facades;

use Illuminate\Support\Facades\Facade;

class AgentGraph extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'agent-graph';
    }
}
