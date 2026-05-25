<?php

use Heiner\AgentGraph\Contracts\Node;
use Heiner\AgentGraph\Facades\AgentGraph;
use Heiner\AgentGraph\Graph\StateGraph;
use Heiner\AgentGraph\Runtime\NodeContext;
use Heiner\AgentGraph\Runtime\NodeResult;

it('passes resume payload and interrupt context to the resumed node only', function () {
    AgentGraph::define(
        StateGraph::make('resume_context')
            ->state([
                'input' => 'string|null',
                'order_id' => 'string|null',
                'resume_payload' => 'array|null',
                'interrupt_type' => 'string|null',
                'interrupt_response' => 'array|null',
                'downstream_saw_resume' => 'bool|null',
            ])
            ->node('ask', ResumeContextAskNode::class)
            ->node('answer', ResumeContextAnswerNode::class)
            ->edge(StateGraph::START, 'ask')
            ->edge('ask', 'answer')
            ->edge('answer', StateGraph::END)
    );

    $interrupted = AgentGraph::graph('resume_context')
        ->thread('thread-resume-context')
        ->input(['input' => 'Track package'])
        ->run();

    $completed = AgentGraph::resume($interrupted->runId(), [
        'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
        'order_id' => 'ORD-456',
        'source' => 'chat',
    ]);

    expect($completed->completed())->toBeTrue()
        ->and($completed->state('order_id'))->toBe('ORD-456')
        ->and($completed->state('resume_payload'))->toMatchArray([
            'order_id' => 'ORD-456',
            'source' => 'chat',
        ])
        ->and($completed->state('interrupt_type'))->toBe('input')
        ->and($completed->state('interrupt_response'))->toMatchArray([
            'interrupt_id' => $interrupted->interrupt()['interrupt_id'],
            'order_id' => 'ORD-456',
            'source' => 'chat',
        ])
        ->and($completed->state('downstream_saw_resume'))->toBeFalse();
});

final class ResumeContextAskNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        if (! $context->hasResumePayload()) {
            return NodeResult::interrupt('input', ['prompt' => 'What is your order number?']);
        }

        return NodeResult::write([
            'order_id' => $context->resumePayload('order_id'),
            'resume_payload' => $context->resumePayload(),
            'interrupt_type' => $context->pendingInterrupt()['type'] ?? null,
            'interrupt_response' => $context->resolvedInterruptResponse(),
        ]);
    }
}

final class ResumeContextAnswerNode implements Node
{
    public function __invoke(NodeContext $context): NodeResult
    {
        return NodeResult::write([
            'downstream_saw_resume' => $context->hasResumePayload(),
        ]);
    }
}
