<?php

namespace Heiner\AgentGraph\Graph;

class GraphValidationReport
{
    public function __construct(
        protected array $errors = [],
        protected array $warnings = [],
    ) {}

    public static function make(): self
    {
        return new self;
    }

    public function error(string $code, string $message, array $context = []): self
    {
        $this->errors[] = array_merge([
            'code' => $code,
            'message' => $message,
        ], $context);

        return $this;
    }

    public function warning(string $code, string $message, array $context = []): self
    {
        $this->warnings[] = array_merge([
            'code' => $code,
            'message' => $message,
        ], $context);

        return $this;
    }

    public function failed(): bool
    {
        return $this->errors !== [];
    }

    public function passed(): bool
    {
        return ! $this->failed();
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
