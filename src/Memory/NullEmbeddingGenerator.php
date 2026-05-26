<?php

namespace Heiner\AgentGraph\Memory;

use Heiner\AgentGraph\Contracts\EmbeddingGenerator;

class NullEmbeddingGenerator implements EmbeddingGenerator
{
    public function embed(array $texts): array
    {
        return array_map(fn (string $text): array => [(float) strlen($text)], $texts);
    }
}
