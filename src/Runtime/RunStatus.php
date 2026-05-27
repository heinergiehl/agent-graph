<?php

namespace Heiner\AgentGraph\Runtime;

final class RunStatus
{
    public const RUNNING = 'running';

    public const INTERRUPTED = 'interrupted';

    public const DELAYED = 'delayed';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public const ACTIVE = [
        self::RUNNING,
        self::INTERRUPTED,
        self::DELAYED,
    ];

    public const TERMINAL = [
        self::COMPLETED,
        self::CANCELLED,
        self::FAILED,
    ];

    public static function isActive(?string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }

    public static function isTerminal(?string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }
}
