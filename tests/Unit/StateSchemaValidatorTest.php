<?php

use Heiner\AgentGraph\State\StateSchema;
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

it('rejects unknown primitive union and structured schema types', function () {
    $validator = new StateSchemaValidator;

    expect(fn () => $validator->assertPatch(['name' => 'strng'], ['name' => 'Heiner']))
        ->toThrow(InvalidArgumentException::class, 'Unknown state schema type [strng].');

    expect(fn () => $validator->assertPatch(['name' => 'string|strng'], ['name' => 'Heiner']))
        ->toThrow(InvalidArgumentException::class, 'Unknown state schema type [strng].');

    expect(fn () => $validator->assertPatch(['items' => ['type' => 'list']], ['items' => ['a']]))
        ->toThrow(InvalidArgumentException::class, 'Unknown structured state schema type [list].');
});

it('validates structured array items from the StateSchema builder', function () {
    $schema = StateSchema::make()->array('ids', 'string')->toArray();
    $validator = new StateSchemaValidator;

    $validator->assertPatch($schema, ['ids' => ['a', 'b']]);

    expect(fn () => $validator->assertPatch($schema, ['ids' => ['a', 1]]))
        ->toThrow(InvalidArgumentException::class, 'State value [ids] must match schema type [{"type":"array","items":"string"}].');
});

it('validates nested object properties and rejects unknown nested types', function () {
    $validator = new StateSchemaValidator;
    $schema = [
        'profile' => [
            'type' => 'object',
            'properties' => [
                'name' => 'string',
                'age' => 'int|null',
            ],
        ],
    ];

    $validator->assertPatch($schema, ['profile' => ['name' => 'Ada', 'age' => null]]);

    expect(fn () => $validator->assertPatch($schema, ['profile' => ['name' => 'Ada', 'age' => 'old']]))
        ->toThrow(InvalidArgumentException::class, 'State value [profile] must match schema type [{"type":"object","properties":{"name":"string","age":"int|null"}}].');

    expect(fn () => $validator->assertPatch([
        'profile' => [
            'type' => 'object',
            'properties' => ['name' => 'strng'],
        ],
    ], ['profile' => ['name' => 'Ada']]))
        ->toThrow(InvalidArgumentException::class, 'Unknown state schema type [strng].');
});
