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
            'meta' => $meta,
        ]);
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
