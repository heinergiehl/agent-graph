<?php

use Heiner\AgentGraph\State\StateSchemaValidator;

it('accepts supported schema types', function (string $type, mixed $value) {
    (new StateSchemaValidator)->assertPatch(['field' => $type], ['field' => $value]);

    expect(true)->toBeTrue();
})->with([
    ['string', 'hello'],
    ['int', 1],
    ['integer', 1],
    ['float', 1.5],
    ['float', 1],
    ['double', 1.5],
    ['bool', true],
    ['boolean', false],
    ['array', ['a']],
    ['messages', [['role' => 'user', 'content' => 'hello']]],
    ['object', new stdClass],
    ['mixed', null],
    ['mixed', 'anything'],
]);

it('accepts null only for nullable unions or mixed', function () {
    $validator = new StateSchemaValidator;

    $validator->assertPatch(['name' => 'string|null', 'anything' => 'mixed'], ['name' => null, 'anything' => null]);

    expect(fn () => $validator->assertPatch(['name' => 'string'], ['name' => null]))
        ->toThrow(InvalidArgumentException::class, 'State value [name] must match schema type [string].');
});

it('rejects values that do not match their schema type', function () {
    expect(fn () => (new StateSchemaValidator)->assertPatch(['count' => 'int'], ['count' => '1']))
        ->toThrow(InvalidArgumentException::class, 'State value [count] must match schema type [int].');
});

it('rejects unknown keys in strict mode', function () {
    expect(fn () => (new StateSchemaValidator)->assertPatch(['known' => 'string'], ['unknown' => 'value']))
        ->toThrow(InvalidArgumentException::class, 'State patch contains unknown state key [unknown].');
});

it('allows unknown keys in non strict mode while validating known keys', function () {
    $validator = new StateSchemaValidator;

    $validator->assertPatch(['known' => 'string'], ['known' => 'value', 'unknown' => 1], strictKeys: false);

    expect(fn () => $validator->assertPatch(['known' => 'string'], ['known' => 1, 'unknown' => 1], strictKeys: false))
        ->toThrow(InvalidArgumentException::class, 'State value [known] must match schema type [string].');
});
