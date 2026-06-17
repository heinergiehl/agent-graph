<?php

use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\GraphSchemaExporter;
use Heiner\AgentGraph\Graph\GraphValidator;
use Heiner\AgentGraph\Graph\InterruptContract;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\State\Reducer;
use Heiner\AgentGraph\State\StateSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

it('creates typed interrupt contracts and persists normalized payloads', function () {
    AgentGraph::define(
        StateGraph::make('typed_interrupt_contract')
            ->state([
                'email' => 'string|null',
            ])
            ->node('collect_email', TypedInterruptAskNode::class)
            ->edge(StateGraph::START, 'collect_email')
            ->compile(),
    );

    $run = AgentGraph::graph('typed_interrupt_contract')
        ->thread('typed-interrupt-thread')
        ->run();

    expect($run->status())->toBe('interrupted')
        ->and($run->interrupt()['type'])->toBe('input')
        ->and($run->interrupt()['payload'])->toMatchArray([
            'contract_version' => 1,
            'node_id' => 'collect_email',
            'reason' => 'waiting_for_input',
            'output' => 'Which email should receive the follow-up?',
            'interaction' => [
                'kind' => 'slot_value',
                'source' => 'agent_graph',
                'node_id' => 'collect_email',
                'question' => 'Which email should receive the follow-up?',
                'answer_types' => ['slot_value', 'cancel'],
                'side_effect' => 'read',
                'slot' => [
                    'name' => 'email',
                    'input_type' => 'email',
                    'required' => true,
                    'allows_multiple' => false,
                ],
                'policy' => [],
            ],
        ]);
});

it('validates slot interrupt responses before resolving typed contract resumes', function () {
    AgentGraph::define(
        StateGraph::make('slot_contract_resume')
            ->state(['email' => 'string|null'])
            ->node('collect_email', SlotContractResumeNode::class)
            ->edge(StateGraph::START, 'collect_email')
            ->compile(),
    );

    $run = AgentGraph::graph('slot_contract_resume')->thread('slot-contract-resume')->run();
    $interruptId = $run->interrupt()['interrupt_id'];

    expect(fn () => AgentGraph::resumeContract($run->runId(), [
        'interrupt_id' => $interruptId,
        'wrong' => 'ada@example.com',
    ]))->toThrow(InvalidArgumentException::class, 'requires response key [email]');

    $completed = AgentGraph::resumeContract($run->runId(), [
        'interrupt_id' => $interruptId,
        'email' => 'ada@example.com',
    ]);

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('email'))->toBe('ada@example.com');
});

it('validates approval interrupt responses before resolving typed contract resumes', function () {
    AgentGraph::define(
        StateGraph::make('approval_contract_resume')
            ->state(['decision' => 'string|null'])
            ->node('approve_change', ApprovalContractResumeNode::class)
            ->edge(StateGraph::START, 'approve_change')
            ->compile(),
    );

    $run = AgentGraph::graph('approval_contract_resume')->thread('approval-contract-resume')->run();
    $interruptId = $run->interrupt()['interrupt_id'];

    expect(fn () => AgentGraph::resumeContract($run->runId(), [
        'interrupt_id' => $interruptId,
        'answer_type' => 'maybe',
    ]))->toThrow(InvalidArgumentException::class, 'must be one of [approve, reject, edit]');

    $completed = AgentGraph::resumeContract($run->runId(), [
        'interrupt_id' => $interruptId,
        'answer_type' => 'approve',
    ]);

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('decision'))->toBe('approve');
});

it('validates choice interrupt responses before resolving typed contract resumes', function () {
    AgentGraph::define(
        StateGraph::make('choice_contract_resume')
            ->state(['choice' => 'string|null'])
            ->node('choose_mode', ChoiceContractResumeNode::class)
            ->edge(StateGraph::START, 'choose_mode')
            ->compile(),
    );

    $run = AgentGraph::graph('choice_contract_resume')->thread('choice-contract-resume')->run();
    $interruptId = $run->interrupt()['interrupt_id'];

    expect(fn () => AgentGraph::resumeContract($run->runId(), [
        'interrupt_id' => $interruptId,
        'choice' => 'green',
    ]))->toThrow(InvalidArgumentException::class, 'is not an allowed choice');

    $completed = AgentGraph::resumeContract($run->runId(), [
        'interrupt_id' => $interruptId,
        'choice' => 'blue',
    ]);

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('choice'))->toBe('blue');
});

it('exports graph manifests with state schema reducers routes and policies', function () {
    $definition = StateGraph::make('manifest_graph', '2')
        ->state(StateSchema::make()
            ->string('message')
            ->channel('flexible', 'string|int|null')
            ->enum('mode', ['short', 'long'])
            ->array('summaries', 'string'))
        ->reducer('summaries', Reducer::append())
        ->node('classify', ManifestClassifyNode::class)
        ->node('answer', ManifestAnswerNode::class)
        ->nodeMeta('classify', ['label' => 'Classify request', 'type' => 'router'])
        ->nodeChannels('classify', input: ['message', 'mode'], output: ['summaries'])
        ->nodeCanInterrupt('answer')
        ->nodeSideEffects('answer', ['write', 'external_api'])
        ->edge(StateGraph::START, 'classify')
        ->conditional('classify', fn (array $state): string => $state['mode'] === 'short' ? 'answer' : StateGraph::END, [
            'answer' => 'answer',
            'end' => StateGraph::END,
        ])
        ->retry('answer', maxAttempts: 2, delayMs: 50)
        ->timeout('answer', 15)
        ->compile();

    $manifest = $definition->manifest()->toArray();
    $legacyManifest = $definition->manifest()->toArray(1);

    expect($manifest)->toMatchArray([
        'manifest_version' => 2,
        'key' => 'manifest_graph',
        'version' => '2',
        'state' => [
            'message' => ['type' => 'string', 'nullable' => false],
            'flexible' => ['type' => ['string', 'integer'], 'nullable' => true],
            'mode' => ['type' => 'enum', 'values' => ['short', 'long'], 'nullable' => false],
            'summaries' => ['type' => 'array', 'items' => ['type' => 'string', 'nullable' => false], 'nullable' => false],
        ],
        'reducers' => [
            'summaries' => 'append',
        ],
        'edges' => [
            StateGraph::START => ['classify'],
        ],
        'conditionals' => [
            'classify' => [
                'routes' => [
                    'answer' => 'answer',
                    'end' => StateGraph::END,
                ],
            ],
        ],
        'policies' => [
            'answer' => [
                'retry' => [
                    'max_attempts' => 2,
                    'delay_ms' => 50,
                    'backoff' => 1.0,
                    'max_delay_ms' => null,
                ],
                'timeout' => [
                    'seconds' => 15.0,
                ],
            ],
        ],
    ]);

    expect($manifest['nodes']['classify'])->toMatchArray([
        'id' => 'classify',
        'metadata' => ['label' => 'Classify request', 'type' => 'router'],
        'input_channels' => ['message', 'mode'],
        'output_channels' => ['summaries'],
        'can_interrupt' => false,
        'side_effects' => [],
    ])
        ->and($manifest['nodes']['answer'])->toMatchArray([
            'id' => 'answer',
            'metadata' => [],
            'input_channels' => [],
            'output_channels' => [],
            'can_interrupt' => true,
            'side_effects' => ['write', 'external_api'],
        ])
        ->and($manifest['nodes']['answer'])->not->toHaveKeys(['class', 'callable'])
        ->and($legacyManifest['nodes']['answer']['class'])->toBe(ManifestAnswerNode::class)
        ->and($legacyManifest['state']['flexible'])->toBe(['type' => ['string', 'int'], 'nullable' => true])
        ->and($legacyManifest['state']['summaries'])->toBe([
            'type' => 'array',
            'nullable' => false,
            'items' => [
                'type' => 'string',
                'nullable' => false,
            ],
        ]);
});

it('exports exact json schema like graph state definitions through a neutral exporter', function () {
    $export = (new GraphSchemaExporter)->state([
        'message' => 'string',
        'score' => 'int|float|null',
        'enabled' => 'bool',
        'tags' => [
            'type' => 'array',
            'items' => 'string|null',
            'nullable' => true,
        ],
        'profile' => [
            'type' => 'object',
            'properties' => [
                'name' => 'string',
                'age' => 'int|null',
                'preferences' => [
                    'type' => 'object',
                    'properties' => [
                        'timezone' => 'string',
                    ],
                ],
            ],
        ],
        'status' => [
            'type' => 'enum',
            'values' => ['draft', 'sent'],
            'nullable' => true,
        ],
        'messages' => 'messages',
    ]);

    expect($export)->toMatchArray([
        'message' => ['type' => 'string', 'nullable' => false],
        'score' => ['type' => ['integer', 'number'], 'nullable' => true],
        'enabled' => ['type' => 'boolean', 'nullable' => false],
        'tags' => [
            'type' => 'array',
            'nullable' => true,
            'items' => ['type' => 'string', 'nullable' => true],
        ],
        'profile' => [
            'type' => 'object',
            'nullable' => false,
            'properties' => [
                'name' => ['type' => 'string', 'nullable' => false],
                'age' => ['type' => 'integer', 'nullable' => true],
                'preferences' => [
                    'type' => 'object',
                    'nullable' => false,
                    'properties' => [
                        'timezone' => ['type' => 'string', 'nullable' => false],
                    ],
                ],
            ],
        ],
        'status' => ['type' => 'enum', 'values' => ['draft', 'sent'], 'nullable' => true],
        'messages' => ['type' => 'array', 'format' => 'messages', 'nullable' => false, 'items' => ['type' => 'mixed', 'nullable' => true]],
    ]);
});

it('validates graph definitions for release readiness without throwing immediately', function () {
    $definition = StateGraph::make('invalid_manifest_graph')
        ->state([
            'message' => 'strng',
            'items' => 'array',
        ])
        ->reducer('items', 'unknown_reducer')
        ->node('reachable', ManifestClassifyNode::class)
        ->node('orphan', ManifestAnswerNode::class)
        ->edge(StateGraph::START, 'reachable')
        ->compile();

    $report = GraphValidator::validate($definition);

    expect($report->failed())->toBeTrue()
        ->and(collect($report->errors())->contains(fn (array $error): bool => $error['code'] === 'unknown_state_schema_type'))->toBeTrue()
        ->and(collect($report->errors())->contains(fn (array $error): bool => $error['code'] === 'unknown_reducer'))->toBeTrue()
        ->and(collect($report->warnings())->contains(fn (array $warning): bool => $warning['code'] === 'unreachable_node' && $warning['node'] === 'orphan'))->toBeTrue();
});

it('mirrors runtime conditional precedence when validating reachability', function () {
    $definition = StateGraph::make('conditional_precedence_validation')
        ->state(['message' => 'string|null'])
        ->node('router', ManifestClassifyNode::class)
        ->node('actual', ManifestAnswerNode::class)
        ->node('ignored_static_target', ManifestAnswerNode::class)
        ->edge(StateGraph::START, 'router')
        ->edge('router', 'ignored_static_target')
        ->conditional('router', fn (): string => 'actual', [
            'actual' => 'actual',
        ])
        ->compile();

    $report = GraphValidator::validate($definition);

    expect(collect($report->warnings())->contains(fn (array $warning): bool => $warning['code'] === 'unreachable_node' && $warning['node'] === 'ignored_static_target'))->toBeTrue()
        ->and(collect($report->warnings())->contains(fn (array $warning): bool => $warning['code'] === 'unreachable_node' && $warning['node'] === 'actual'))->toBeFalse();
});

it('reports production graph warnings with stable issue metadata', function () {
    $definition = StateGraph::make('validator_warning_graph')
        ->state(['route' => 'string|null'])
        ->node('router', ManifestClassifyNode::class)
        ->node('terminal', ManifestAnswerNode::class)
        ->node('ignored_static_target', ManifestAnswerNode::class)
        ->edge(StateGraph::START, 'router')
        ->edge('router', 'ignored_static_target')
        ->conditional('router', fn (): string => 'terminal', [
            'terminal' => 'terminal',
        ])
        ->compile();

    $report = GraphValidator::validate($definition);
    $warningCodes = collect($report->warnings())->pluck('code')->all();

    expect($warningCodes)->toContain('conditional_without_default')
        ->and($warningCodes)->toContain('mixed_static_conditional_outgoing')
        ->and($warningCodes)->toContain('terminal_path')
        ->and($report->failed())->toBeFalse()
        ->and($report->failed(strict: true))->toBeTrue()
        ->and($report->issues()[0])->toHaveKeys(['severity', 'code', 'message'])
        ->and($report->toArray(strict: true))->toMatchArray([
            'passed' => false,
            'failed' => true,
            'strict' => true,
            'error_count' => 0,
        ])
        ->and($report->toArray(strict: true)['warning_count'])->toBeGreaterThanOrEqual(3);
});

it('derives graph tool input schema from registered graph state schema', function () {
    AgentGraph::define(
        StateGraph::make('schema_tool_graph')
            ->state(StateSchema::make()
                ->string('message')
                ->integer('limit')
                ->enum('mode', ['summary', 'detail'])
                ->channel('flexible', 'string|int|null')
                ->channel('optional_note', 'string|null'))
            ->node('answer', ManifestAnswerNode::class)
            ->edge(StateGraph::START, 'answer')
            ->compile(),
    );

    $schema = AgentGraph::tool('schema_tool_graph')->schema(new JsonSchemaTypeFactory);
    $input = $schema['input']->toArray();

    expect($input['type'])->toBe('object')
        ->and($input)->not->toHaveKey('required')
        ->and($input['properties']['message']['type'])->toBe('string')
        ->and($input['properties']['limit']['type'])->toBe('integer')
        ->and($input['properties']['mode']['enum'])->toBe(['summary', 'detail'])
        ->and($input['properties']['flexible']['type'])->toBe(['string', 'null'])
        ->and($input['properties']['flexible']['description'])->toContain('Accepts one of: string, integer, null.')
        ->and($input['properties']['optional_note']['type'])->toBe(['string', 'null']);
});

it('allows explicit graph tool input schema overrides', function () {
    AgentGraph::define(
        StateGraph::make('schema_override_tool_graph')
            ->state([
                'internal_state' => 'string',
                'answer' => 'string|null',
            ])
            ->node('answer', ManifestAnswerNode::class)
            ->edge(StateGraph::START, 'answer')
            ->compile(),
    );

    $schema = AgentGraph::tool('schema_override_tool_graph')
        ->schemaInput(fn (JsonSchemaTypeFactory $schema) => $schema->object([
            'message' => $schema->string()->required(),
        ]))
        ->schema(new JsonSchemaTypeFactory);

    $input = $schema['input']->toArray();

    expect($input['properties'])->toHaveKey('message')
        ->and($input['properties'])->not->toHaveKey('internal_state')
        ->and($input['required'])->toBe(['message']);
});

final class TypedInterruptAskNode
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::interruptContract(
            InterruptContract::slotValue(
                nodeId: 'collect_email',
                question: 'Which email should receive the follow-up?',
                slot: 'email',
                inputType: 'email',
            ),
        );
    }
}

final class SlotContractResumeNode
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->hasResumePayload()) {
            return NodeResult::end(['email' => (string) ($context->resumePayload()['email'] ?? '')]);
        }

        return NodeResult::interruptContract(
            InterruptContract::slotValue(
                nodeId: 'collect_email',
                question: 'Which email should receive the follow-up?',
                slot: 'email',
                inputType: 'email',
            ),
        );
    }
}

final class ApprovalContractResumeNode
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->hasResumePayload()) {
            return NodeResult::end(['decision' => (string) ($context->resumePayload()['answer_type'] ?? '')]);
        }

        return NodeResult::interruptContract(
            InterruptContract::approval(
                nodeId: 'approve_change',
                title: 'Approve update',
                summary: 'Approve the account update.',
            ),
        );
    }
}

final class ChoiceContractResumeNode
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if ($context->hasResumePayload()) {
            return NodeResult::end(['choice' => (string) ($context->resumePayload()['choice'] ?? '')]);
        }

        return NodeResult::interruptContract(
            InterruptContract::choice(
                nodeId: 'choose_mode',
                question: 'Which mode should run?',
                choices: ['red', 'blue'],
            ),
        );
    }
}

final class ManifestClassifyNode
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([]);
    }
}

final class ManifestAnswerNode
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::end();
    }
}
