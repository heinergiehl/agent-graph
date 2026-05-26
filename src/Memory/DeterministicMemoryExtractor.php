<?php

namespace Heiner\AgentGraph\Memory;

use Heiner\AgentGraph\Contracts\MemoryExtractor;

class DeterministicMemoryExtractor implements MemoryExtractor
{
    public function extract(string $text, array $meta = []): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(function (string $sentence, int $index) use ($meta): array {
            $content = trim($sentence);

            return [
                'key' => 'memory_'.substr(hash('sha256', $content), 0, 16),
                'value' => ['content' => $content],
                'type' => $meta['type'] ?? 'fact',
                'content' => $content,
                'meta' => array_merge($meta, ['extractor_index' => $index]),
            ];
        }, $sentences, array_keys($sentences));
    }
}
