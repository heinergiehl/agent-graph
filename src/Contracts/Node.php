<?php

namespace Heiner\AgentGraph\Contracts;

use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

interface Node
{
    public function __invoke(NodeContext $context): NodeResult;
}
