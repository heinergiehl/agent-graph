<?php

use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\GraphValidator;
use Heiner\AgentGraph\Graph\InterruptContract;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;
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

it('exports graph manifests with state schema reducers routes and policies', function () {
    $definition = StateGraph::make('manifest_graph', '2')
        ->state(StateSchema::make()
            ->string('message')
            ->enum('mode', ['short', 'long'])
            ->array('summaries', 'string'))
        ->reducer('summaries', 'append')
        ->node('classify', ManifestClassifyNode::class)
        ->node('answer', ManifestAnswerNode::class)
        ->edge(StateGraph::START, 'classify')
        ->conditional('classify', fn (array $state): string => $state['mode'] === 'short' ? 'answer' : StateGraph::END, [
            'answer' => 'answer',
            'end' => StateGraph::END,
        ])
        ->retry('answer', maxAttempts: 2, delayMs: 50)
        ->timeout('answer', 15)
        ->compile();

    $manifest = $definition->manifest()->toArray();

    expect($manifest)->toMatchArray([
        'key' => 'manifest_graph',
        'version' => '2',
        'state' => [
            'message' => ['type' => 'string', 'nullable' => false],
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

    expect($manifest['nodes']['answer']['class'])->toBe(ManifestAnswerNode::class);
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

it('derives graph tool input schema from registered graph state schema', function () {
    AgentGraph::define(
        StateGraph::make('schema_tool_graph')
            ->state(StateSchema::make()
                ->string('message')
                ->integer('limit')
                ->enum('mode', ['summary', 'detail'])
                ->channel('optional_note', 'string|null'))
            ->node('answer', ManifestAnswerNode::class)
            ->edge(StateGraph::START, 'answer')
            ->compile(),
    );

    $schema = AgentGraph::tool('schema_tool_graph')->schema(new JsonSchemaTypeFactory);
    $input = $schema['input']->toArray();

    expect($input['type'])->toBe('object')
        ->and($input['required'])->toContain('message')
        ->and($input['required'])->toContain('limit')
        ->and($input['required'])->toContain('mode')
        ->and($input['required'])->not->toContain('optional_note')
        ->and($input['properties']['message']['type'])->toBe('string')
        ->and($input['properties']['limit']['type'])->toBe('integer')
        ->and($input['properties']['mode']['enum'])->toBe(['summary', 'detail'])
        ->and($input['properties']['optional_note']['type'])->toBe(['string', 'null']);
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
