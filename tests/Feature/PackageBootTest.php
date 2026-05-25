<?php

use Heiner\AgentGraph\AgentGraphManager;
use Heiner\AgentGraph\Facades\AgentGraph;

it('boots the package and resolves the facade', function () {
    expect(app('agent-graph'))->toBeInstanceOf(AgentGraphManager::class)
        ->and(AgentGraph::getFacadeRoot())->toBeInstanceOf(AgentGraphManager::class);
});
