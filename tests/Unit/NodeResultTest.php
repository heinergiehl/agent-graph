<?php

use Heiner\AgentGraph\Runtime\NodeResult;

it('represents state writes', function () {
    $result = NodeResult::write(['answer' => 'Hello'])->withMeta(['tokens' => 12]);

    expect($result->writes())->toBe(['answer' => 'Hello'])
        ->and($result->status())->toBe('continue')
        ->and($result->meta())->toBe(['tokens' => 12]);
});

it('represents goto, interrupt, end, and failure commands', function () {
    expect(NodeResult::goto('review')->nextNode())->toBe('review');

    $interrupt = NodeResult::interrupt('approval', ['title' => 'Approve']);
    expect($interrupt->status())->toBe('interrupted')
        ->and($interrupt->interruptType())->toBe('approval')
        ->and($interrupt->interruptPayload())->toBe(['title' => 'Approve']);

    expect(NodeResult::end(['answer' => 'Done'])->status())->toBe('completed')
        ->and(NodeResult::fail('bad', ['node' => 'x'])->status())->toBe('failed');
});
