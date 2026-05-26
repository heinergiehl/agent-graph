<?php

namespace Heiner\AgentGraph\Contracts;

interface EmbeddingGenerator
{
    /**
     * @return array<int, array<float>>
     */
    public function embed(array $texts): array;
}
