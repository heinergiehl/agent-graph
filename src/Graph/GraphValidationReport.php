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
            'severity' => 'error',
            'code' => $code,
            'message' => $message,
        ], $context);

        return $this;
    }

    public function warning(string $code, string $message, array $context = []): self
    {
        $this->warnings[] = array_merge([
            'severity' => 'warning',
            'code' => $code,
            'message' => $message,
        ], $context);

        return $this;
    }

    public function failed(bool $strict = false): bool
    {
        return $this->errors !== [] || ($strict && $this->warnings !== []);
    }

    public function passed(bool $strict = false): bool
    {
        return ! $this->failed($strict);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    public function issues(): array
    {
        return array_values(array_merge($this->errors, $this->warnings));
    }

    public function toArray(bool $strict = false): array
    {
        return [
            'passed' => $this->passed($strict),
            'failed' => $this->failed($strict),
            'strict' => $strict,
            'error_count' => count($this->errors),
            'warning_count' => count($this->warnings),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'issues' => $this->issues(),
        ];
    }
}
