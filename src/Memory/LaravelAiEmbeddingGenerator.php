<?php

namespace Heiner\AgentGraph\Memory;

use Heiner\AgentGraph\Contracts\EmbeddingGenerator;
use Laravel\Ai\Embeddings;

class LaravelAiEmbeddingGenerator implements EmbeddingGenerator
{
    public function embed(array $texts): array
    {
        return Embeddings::for($texts)->generate()->embeddings;
    }
}
