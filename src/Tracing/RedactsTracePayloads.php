<?php

namespace Heiner\AgentGraph\Tracing;

trait RedactsTracePayloads
{
    protected function redactPayload(array $payload): array
    {
        $keys = array_map('strtolower', config('agent-graph.tracing.redact_keys', []));
        $max = (int) config('agent-graph.tracing.max_string_length', 2000);
        $maxPayload = (int) config('agent-graph.tracing.max_payload_size', 65535);

        $redacted = $this->redactValue($payload, $keys, $max);
        $encoded = json_encode($redacted);

        if ($encoded === false || strlen($encoded) <= $maxPayload) {
            return $redacted;
        }

        return [
            '_truncated' => true,
            '_original_size' => strlen($encoded),
            'preview' => substr($encoded, 0, $maxPayload),
        ];
    }

    protected function redactValue(mixed $value, array $keys, int $max, ?string $key = null): mixed
    {
        if ($key !== null && in_array(strtolower($key), $keys, true)) {
            return '[redacted]';
        }

        if (is_string($value)) {
            return strlen($value) > $max ? substr($value, 0, $max) : $value;
        }

        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                $value[$childKey] = $this->redactValue($childValue, $keys, $max, (string) $childKey);
            }
        }

        return $value;
    }
}
