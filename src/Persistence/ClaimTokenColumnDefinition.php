<?php

namespace Heiner\AgentGraph\Persistence;

use RuntimeException;

/** @internal */
final class ClaimTokenColumnDefinition
{
    public const NAME = 'claim_token';

    public const LENGTH = 26;

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, mixed>|null
     */
    public static function find(array $columns): ?array
    {
        foreach ($columns as $column) {
            if (strtolower((string) ($column['name'] ?? '')) === self::NAME) {
                return $column;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $column */
    public static function assertCompatible(array $column, string $driver, string $table): void
    {
        $typeName = self::normalizeType((string) ($column['type_name'] ?? ''));
        $type = self::normalizeType((string) ($column['type'] ?? ''));
        $expectedTypeNames = $driver === 'sqlsrv'
            ? ['nvarchar']
            : ['varchar', 'character varying'];

        $compatible = strtolower((string) ($column['name'] ?? '')) === self::NAME
            && in_array($typeName, $expectedTypeNames, true)
            && self::hasExpectedLength($type, $driver)
            && ($column['nullable'] ?? null) === true
            && array_key_exists('default', $column)
            && $column['default'] === null
            && ($column['auto_increment'] ?? null) === false
            && array_key_exists('generation', $column)
            && $column['generation'] === null;

        if ($compatible) {
            return;
        }

        $actual = json_encode([
            'type_name' => $column['type_name'] ?? null,
            'type' => $column['type'] ?? null,
            'nullable' => $column['nullable'] ?? null,
            'default' => $column['default'] ?? null,
            'auto_increment' => $column['auto_increment'] ?? null,
            'generation' => $column['generation'] ?? null,
        ], JSON_UNESCAPED_SLASHES);

        throw new RuntimeException(sprintf(
            'Existing column [%s.%s] is incompatible: expected nullable varchar(%d) without a default, auto-increment, or generated expression for driver [%s]; got %s.',
            $table,
            self::NAME,
            self::LENGTH,
            $driver,
            $actual === false ? 'an unreadable schema definition' : $actual,
        ));
    }

    private static function normalizeType(string $type): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower($type)));
    }

    private static function hasExpectedLength(string $type, string $driver): bool
    {
        if (preg_match('/^(?:character varying|varchar|nvarchar)\s*\(\s*(\d+)\s*\)$/', $type, $matches) === 1) {
            return (int) $matches[1] === self::LENGTH;
        }

        // SQLite's schema grammar emits VARCHAR without retaining the declared
        // length, so nullability and logical type are the enforceable contract.
        return $driver === 'sqlite' && in_array($type, ['varchar', 'nvarchar'], true);
    }
}
