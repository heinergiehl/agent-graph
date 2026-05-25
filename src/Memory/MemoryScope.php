<?php

namespace Heiner\AgentGraph\Memory;

class MemoryScope
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly ?string $tenantId = null,
    ) {}

    public static function run(string $runId): self
    {
        return new self('run', $runId);
    }

    public static function thread(string $threadId): self
    {
        return new self('thread', $threadId);
    }

    public static function actor(string $tenantId, string $actorId): self
    {
        return new self('actor', $actorId, $tenantId);
    }

    public static function tenant(string $tenantId): self
    {
        return new self('tenant', $tenantId);
    }

    public static function application(string $applicationId): self
    {
        return new self('application', $applicationId);
    }

    public static function global(): self
    {
        return new self('global', 'global');
    }
}
