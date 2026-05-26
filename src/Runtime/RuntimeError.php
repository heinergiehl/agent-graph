<?php

namespace Heiner\AgentGraph\Runtime;

use Throwable;

class RuntimeError
{
    public static function fromThrowable(Throwable $exception, array $details = [], array $meta = []): array
    {
        return self::normalize(
            message: $exception->getMessage(),
            exceptionClass: $exception::class,
            code: $exception->getCode(),
            previous: $exception->getPrevious() instanceof Throwable
                ? self::fromThrowable($exception->getPrevious())
                : null,
            details: $details,
            meta: $meta,
        );
    }

    public static function fromMessage(string $message, int|string|null $code = null, array $details = [], array $meta = []): array
    {
        return self::normalize(
            message: $message,
            exceptionClass: null,
            code: $code,
            previous: null,
            details: $details,
            meta: $meta,
        );
    }

    protected static function normalize(string $message, ?string $exceptionClass, int|string|null $code, ?array $previous, array $details, array $meta): array
    {
        $payload = [
            'message' => $message,
            'exception_class' => $exceptionClass,
            'code' => $code,
            'previous' => $previous,
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return $payload;
    }
}
