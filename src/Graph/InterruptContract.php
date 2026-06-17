<?php

namespace Heiner\AgentGraph\Graph;

use InvalidArgumentException;

class InterruptContract
{
    public const VERSION = 1;

    protected function __construct(
        protected string $type,
        protected array $payload,
    ) {
        $this->assertValidPayload($payload);
    }

    public static function fromArray(array $payload, string $type = 'input'): self
    {
        return new self($type, $payload);
    }

    public static function slotValue(
        string $nodeId,
        string $question,
        string $slot,
        string $inputType = 'string',
        bool $required = true,
        bool $allowsMultiple = false,
        string $sideEffect = 'read',
        array $answerTypes = ['slot_value', 'cancel'],
        array $policy = [],
        array $meta = [],
    ): self {
        return new self('input', [
            'contract_version' => self::VERSION,
            'node_id' => $nodeId,
            'reason' => 'waiting_for_input',
            'output' => $question,
            'interaction' => [
                'kind' => 'slot_value',
                'source' => 'agent_graph',
                'node_id' => $nodeId,
                'question' => $question,
                'answer_types' => array_values($answerTypes),
                'side_effect' => $sideEffect,
                'slot' => [
                    'name' => $slot,
                    'input_type' => $inputType,
                    'required' => $required,
                    'allows_multiple' => $allowsMultiple,
                ],
                'policy' => $policy,
            ],
            'response_schema' => [
                'kind' => 'slot_value',
                'answer_types' => array_values($answerTypes),
                'slot' => [
                    'name' => $slot,
                    'input_type' => $inputType,
                    'required' => $required,
                    'allows_multiple' => $allowsMultiple,
                ],
            ],
            'meta' => $meta,
        ]);
    }

    public static function approval(
        string $nodeId,
        string $title,
        string $summary,
        string $sideEffect = 'write',
        array $answerTypes = ['approve', 'reject', 'edit'],
        array $policy = [],
        array $meta = [],
    ): self {
        return new self('approval', [
            'contract_version' => self::VERSION,
            'node_id' => $nodeId,
            'reason' => 'waiting_for_approval',
            'output' => $summary,
            'interaction' => [
                'kind' => 'approval',
                'source' => 'agent_graph',
                'node_id' => $nodeId,
                'title' => $title,
                'summary' => $summary,
                'answer_types' => array_values($answerTypes),
                'side_effect' => $sideEffect,
                'policy' => $policy,
            ],
            'response_schema' => [
                'kind' => 'approval',
                'answer_types' => array_values($answerTypes),
                'required' => ['answer_type'],
            ],
            'meta' => $meta,
        ]);
    }

    public static function choice(
        string $nodeId,
        string $question,
        array $choices,
        bool $allowsMultiple = false,
        array $answerTypes = ['choice', 'cancel'],
        array $policy = [],
        array $meta = [],
    ): self {
        if ($choices === []) {
            throw new InvalidArgumentException('Choice interrupt contracts require at least one choice.');
        }

        return new self('input', [
            'contract_version' => self::VERSION,
            'node_id' => $nodeId,
            'reason' => 'waiting_for_choice',
            'output' => $question,
            'interaction' => [
                'kind' => 'choice',
                'source' => 'agent_graph',
                'node_id' => $nodeId,
                'question' => $question,
                'answer_types' => array_values($answerTypes),
                'side_effect' => 'read',
                'choices' => array_values($choices),
                'allows_multiple' => $allowsMultiple,
                'policy' => $policy,
            ],
            'response_schema' => [
                'kind' => 'choice',
                'answer_types' => array_values($answerTypes),
                'choices' => array_values($choices),
                'allows_multiple' => $allowsMultiple,
            ],
            'meta' => $meta,
        ]);
    }

    public static function isContractPayload(array $payload): bool
    {
        return ($payload['contract_version'] ?? null) === self::VERSION
            && is_array($payload['interaction'] ?? null);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function toArray(): array
    {
        return $this->payload;
    }

    public function kind(): string
    {
        return (string) $this->payload['interaction']['kind'];
    }

    public function nodeId(): string
    {
        return (string) $this->payload['node_id'];
    }

    public function responseSchema(): array
    {
        if (is_array($this->payload['response_schema'] ?? null)) {
            return $this->payload['response_schema'];
        }

        $interaction = $this->payload['interaction'];

        return [
            'kind' => (string) $interaction['kind'],
            'answer_types' => array_values((array) ($interaction['answer_types'] ?? [])),
        ];
    }

    public function assertResponse(array $response): void
    {
        match ($this->kind()) {
            'slot_value' => $this->assertSlotValueResponse($response),
            'approval' => $this->assertApprovalResponse($response),
            'choice' => $this->assertChoiceResponse($response),
            default => null,
        };
    }

    protected function assertApprovalResponse(array $response): void
    {
        $answerType = $response['answer_type'] ?? null;

        if (! is_string($answerType) || $answerType === '') {
            throw new InvalidArgumentException('Approval interrupt responses require answer_type.');
        }

        $this->assertAllowedAnswerType($answerType);
    }

    protected function assertChoiceResponse(array $response): void
    {
        $answerType = $response['answer_type'] ?? 'choice';
        $this->assertAllowedAnswerType($answerType);

        if ($answerType !== 'choice') {
            return;
        }

        $schema = $this->responseSchema();
        $choices = array_values((array) ($schema['choices'] ?? $this->payload['interaction']['choices'] ?? []));
        $allowsMultiple = (bool) ($schema['allows_multiple'] ?? $this->payload['interaction']['allows_multiple'] ?? false);

        if (! array_key_exists('choice', $response)) {
            throw new InvalidArgumentException('Choice interrupt responses require response key [choice].');
        }

        $selected = $response['choice'];

        if ($allowsMultiple) {
            if (! is_array($selected) || ! array_is_list($selected)) {
                throw new InvalidArgumentException('Choice interrupt response key [choice] must be a list.');
            }

            foreach ($selected as $choice) {
                $this->assertAllowedChoice($choice, $choices);
            }

            return;
        }

        $this->assertAllowedChoice($selected, $choices);
    }

    protected function assertSlotValueResponse(array $response): void
    {
        $answerType = $response['answer_type'] ?? 'slot_value';
        $this->assertAllowedAnswerType($answerType);

        if ($answerType !== 'slot_value') {
            return;
        }

        $schema = $this->responseSchema();
        $slot = (array) ($schema['slot'] ?? $this->payload['interaction']['slot'] ?? []);
        $name = (string) ($slot['name'] ?? '');

        if ($name === '') {
            throw new InvalidArgumentException('Slot interrupt contracts require a slot name before response validation.');
        }

        if (! array_key_exists($name, $response)) {
            if ((bool) ($slot['required'] ?? true)) {
                throw new InvalidArgumentException("Slot interrupt response requires response key [{$name}].");
            }

            return;
        }

        $value = $response[$name];
        $inputType = (string) ($slot['input_type'] ?? 'string');
        $allowsMultiple = (bool) ($slot['allows_multiple'] ?? false);

        if ($allowsMultiple) {
            if (! is_array($value) || ! array_is_list($value)) {
                throw new InvalidArgumentException("Slot interrupt response key [{$name}] must be a list.");
            }

            foreach ($value as $item) {
                $this->assertSlotValueType($name, $inputType, $item);
            }

            return;
        }

        $this->assertSlotValueType($name, $inputType, $value);
    }

    protected function assertAllowedAnswerType(mixed $answerType): void
    {
        $schema = $this->responseSchema();
        $answerTypes = array_values((array) ($schema['answer_types'] ?? $this->payload['interaction']['answer_types'] ?? []));

        if (! is_string($answerType) || ! in_array($answerType, $answerTypes, true)) {
            throw new InvalidArgumentException('Interrupt response answer_type must be one of ['.implode(', ', $answerTypes).'].');
        }
    }

    protected function assertAllowedChoice(mixed $choice, array $choices): void
    {
        if (! in_array($choice, $choices, true)) {
            $label = is_scalar($choice) ? (string) $choice : get_debug_type($choice);

            throw new InvalidArgumentException("Choice interrupt response value [{$label}] is not an allowed choice.");
        }
    }

    protected function assertSlotValueType(string $name, string $inputType, mixed $value): void
    {
        $valid = match ($inputType) {
            'int', 'integer' => is_int($value),
            'float', 'double', 'number' => is_int($value) || is_float($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            default => is_string($value),
        };

        if (! $valid) {
            throw new InvalidArgumentException("Slot interrupt response key [{$name}] must match input type [{$inputType}].");
        }
    }

    protected function assertValidPayload(array $payload): void
    {
        if (($payload['contract_version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Interrupt contract payloads require contract_version 1.');
        }

        if (! is_string($payload['node_id'] ?? null) || $payload['node_id'] === '') {
            throw new InvalidArgumentException('Interrupt contract payloads require a node_id.');
        }

        if (! is_string($payload['output'] ?? null) || $payload['output'] === '') {
            throw new InvalidArgumentException('Interrupt contract payloads require an output message.');
        }

        if (! is_array($payload['interaction'] ?? null)) {
            throw new InvalidArgumentException('Interrupt contract payloads require an interaction object.');
        }

        $interaction = $payload['interaction'];

        if (! is_string($interaction['kind'] ?? null) || $interaction['kind'] === '') {
            throw new InvalidArgumentException('Interrupt contract interaction requires a kind.');
        }

        if (! is_array($interaction['answer_types'] ?? null) || $interaction['answer_types'] === []) {
            throw new InvalidArgumentException('Interrupt contract interaction requires answer_types.');
        }
    }
}
