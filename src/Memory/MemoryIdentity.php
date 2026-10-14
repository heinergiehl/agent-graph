<?php

namespace Heiner\AgentGraph\Memory;

use InvalidArgumentException;

/**
 * Portable limits and identity of one memory record.
 *
 * The limits keep the composite unique index of the memories table within
 * MySQL's 3,072-byte InnoDB key limit for utf8mb4 (766 characters in total)
 * and are enforced by every bundled memory store, so a value accepted in
 * tests or on SQLite is also accepted on MySQL, MariaDB and PostgreSQL.
 * Lengths are counted in characters, like VARCHAR(n).
 */
final class MemoryIdentity
{
    public const MAX_SCOPE_TYPE_LENGTH = 64;

    public const MAX_SCOPE_ID_LENGTH = 191;

    public const MAX_TENANT_ID_LENGTH = 128;

    public const MAX_NAMESPACE_LENGTH = 128;

    public const MAX_KEY_LENGTH = 255;

    /** @throws InvalidArgumentException when a part exceeds its portable limit */
    public static function assertValid(MemoryScope $scope, string $namespace, string $key): void
    {
        self::assertLength('scope type', $scope->type, self::MAX_SCOPE_TYPE_LENGTH);
        self::assertLength('scope id', $scope->id, self::MAX_SCOPE_ID_LENGTH);
        self::assertLength('tenant id', $scope->tenantId, self::MAX_TENANT_ID_LENGTH);
        self::assertLength('namespace', $namespace, self::MAX_NAMESPACE_LENGTH);
        self::assertLength('key', $key, self::MAX_KEY_LENGTH);
    }

    /**
     * SHA-256 of the complete identity tuple. A null tenant is encoded
     * distinctly from an empty string, so the hash is unique where the
     * composite index is not (SQL unique indexes treat NULLs as distinct).
     */
    public static function hash(MemoryScope $scope, string $namespace, string $key): string
    {
        return self::hashParts($scope->type, $scope->id, $scope->tenantId, $namespace, $key);
    }

    /** @internal Used by the identity-hash backfill migration. */
    public static function hashParts(string $scopeType, string $scopeId, ?string $tenantId, string $namespace, string $key): string
    {
        // Length-prefixed parts are unambiguous for any byte sequence.
        $encoded = 'agent-graph.memory.v1';

        foreach ([$scopeType, $scopeId, $tenantId, $namespace, $key] as $part) {
            $encoded .= $part === null ? '|-' : '|'.strlen($part).':'.$part;
        }

        return hash('sha256', $encoded);
    }

    private static function assertLength(string $label, ?string $value, int $limit): void
    {
        if ($value === null) {
            return;
        }

        $length = mb_strlen($value, 'UTF-8');

        if ($length > $limit) {
            throw new InvalidArgumentException(sprintf(
                'AgentGraph memory %s must not exceed %d characters; got %d.',
                $label,
                $limit,
                $length,
            ));
        }
    }
}
