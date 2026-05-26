<?php

use Heiner\AgentGraph\Runtime\NodeResult;
use Heiner\AgentGraph\Runtime\Send;

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

it('represents send commands with node local input and metadata', function () {
    $send = Send::to('worker', ['item' => 'a'], ['index' => 1]);

    expect($send->node())->toBe('worker')
        ->and($send->input())->toBe(['item' => 'a'])
        ->and($send->meta())->toBe(['index' => 1])
        ->and($send->toArray())->toBe([
            'node' => 'worker',
            'input' => ['item' => 'a'],
            'meta' => ['index' => 1],
        ]);

    $single = NodeResult::send('worker', ['item' => 'b'], ['queued' => 1]);
    $many = NodeResult::sendMany([
        $send,
        Send::to('worker', ['item' => 'c']),
    ], ['queued' => 2]);

    expect($single->writes())->toBe(['queued' => 1])
        ->and($single->sends())->toHaveCount(1)
        ->and($single->sends()[0]->node())->toBe('worker')
        ->and($single->sends()[0]->input())->toBe(['item' => 'b'])
        ->and($many->writes())->toBe(['queued' => 2])
        ->and($many->sends())->toHaveCount(2)
        ->and($many->sends()[1]->input())->toBe(['item' => 'c']);
});
