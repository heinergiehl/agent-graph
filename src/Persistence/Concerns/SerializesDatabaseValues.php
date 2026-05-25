<?php

namespace Heiner\AgentGraph\Persistence\Concerns;

use Heiner\AgentGraph\Exceptions\SerializationException;
use JsonException;

trait SerializesDatabaseValues
{
    protected function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SerializationException('AgentGraph payload is not JSON serializable: '.$exception->getMessage(), previous: $exception);
        }
    }

    protected function decode(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        try {
            return json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SerializationException('AgentGraph payload contains invalid JSON: '.$exception->getMessage(), previous: $exception);
        }
    }

    protected function decodeRecord(object|array $record, array $jsonFields): array
    {
        $record = (array) $record;

        foreach ($jsonFields as $field) {
            if (array_key_exists($field, $record)) {
                $record[$field] = $this->decode($record[$field]);
            }
        }

        return $record;
    }
}
