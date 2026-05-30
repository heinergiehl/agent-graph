<?php

namespace Heiner\AgentGraph\LaravelAi;

use InvalidArgumentException;

final class ToolName
{
    public static function fromGraphKey(string $prefix, string $graphKey): string
    {
        $name = strtolower($prefix.'_'.((string) preg_replace('/[^A-Za-z0-9_]+/', '_', $graphKey)));
        $name = trim((string) preg_replace('/_+/', '_', $name), '_');

        if (strlen($name) <= 64) {
            return self::assertValid($name);
        }

        $hash = substr(hash('xxh128', $name), 0, 8);

        return self::assertValid(substr($name, 0, 55).'_'.$hash);
    }

    public static function assertValid(string $name): string
    {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $name)) {
            throw new InvalidArgumentException('Invalid AI tool name. Use 1-64 characters: letters, numbers, underscore, or hyphen, starting with a letter.');
        }

        return $name;
    }
}
