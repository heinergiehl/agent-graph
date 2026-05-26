<?php

namespace Heiner\AgentGraph\Contracts;

interface MemoryExtractor
{
    public function extract(string $text, array $meta = []): array;
}
