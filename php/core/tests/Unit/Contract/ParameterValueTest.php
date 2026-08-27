<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ParameterValue;

it('reads a string back as the type the contract documents, and leaves it alone otherwise', function (mixed $value, ?array $schema, mixed $expected): void {
    expect(ParameterValue::coerce($value, $schema))->toBe($expected);
})->with([
    'an integer' => ['42', ['type' => 'integer'], 42],
    'a negative integer' => ['-7', ['type' => 'integer'], -7],
    'a nullable integer' => ['42', ['type' => ['integer', 'null']], 42],
    'a number' => ['12.5', ['type' => 'number'], 12.5],
    'an integer literal for a number' => ['12', ['type' => 'number'], 12.0],
    'true' => ['true', ['type' => 'boolean'], true],
    'false' => ['false', ['type' => 'boolean'], false],
    'one' => ['1', ['type' => 'boolean'], true],
    'zero' => ['0', ['type' => 'boolean'], false],
    'a string that is not an integer stays a string' => ['first', ['type' => 'integer'], 'first'],
    'a float where an integer is documented stays a string' => ['1.5', ['type' => 'integer'], '1.5'],
    'a word where a boolean is documented stays a string' => ['yes', ['type' => 'boolean'], 'yes'],
    'a string documented as a string' => ['42', ['type' => 'string'], '42'],
    'no schema at all' => ['42', null, '42'],
    'no type in the schema' => ['42', ['minimum' => 1], '42'],
    'a type that is not a string or a list' => ['42', ['type' => 42], '42'],
    'a list of types with a non-string in it' => ['42', ['type' => [42, 'integer']], 42],
    'a value that is already an integer' => [42, ['type' => 'integer'], 42],
    'a value that is already a bool' => [true, ['type' => 'boolean'], true],
    'null' => [null, ['type' => 'integer'], null],
]);

it('splits a comma list into the array the contract documents, coercing each item', function (): void {
    expect(ParameterValue::coerce('1,2,3', ['type' => 'array', 'items' => ['type' => 'integer']]))->toBe([1, 2, 3])
        ->and(ParameterValue::coerce('a,b', ['type' => 'array']))->toBe(['a', 'b'])
        ->and(ParameterValue::coerce('a', ['type' => 'array', 'items' => ['type' => 'string']]))->toBe(['a']);
});

it('coerces the items of a list that already arrived as one', function (): void {
    expect(ParameterValue::coerce(['1', '2'], ['type' => 'array', 'items' => ['type' => 'integer']]))->toBe([1, 2]);
});

it('reads a bracketed query parameter as the object it stands for', function (): void {
    $coerced = ParameterValue::coerce(
        ['status' => 'paid', 'total' => '12'],
        ['type' => 'object', 'properties' => ['total' => ['type' => 'integer']]],
    );

    expect($coerced)->toBeInstanceOf(stdClass::class)
        ->and($coerced->status)->toBe('paid')
        ->and($coerced->total)->toBe(12);
});

it('keeps a map documented as an array as an array', function (): void {
    expect(ParameterValue::coerce(['a' => '1', 'b' => '2'], ['type' => 'array', 'items' => ['type' => 'integer']]))
        ->toBe(['1', '2']);
});

it('ignores a properties member that is not a map of schemas', function (): void {
    $coerced = ParameterValue::coerce(['a' => '1'], ['type' => 'object', 'properties' => ['a' => 'nope']]);

    expect($coerced->a)->toBe('1');
});

it('reads the type through the same grammar the validator resolves, not off the node in front of it', function (?array $schema, mixed $expected): void {
    // Every spelling here is one the generator itself emits: `representation.nullable = 'anyof'`
    // writes the `anyOf`, the 3.0 downlevel emitter writes the multi-type `anyOf` and the `allOf`
    // wrapper it hoists `$ref` siblings into, and an enum-backed allow-list writes the `$ref`.
    expect(ParameterValue::coerce('1000', $schema, contractSchemaDocument()))->toBe($expected);
})->with([
    'a literal type' => [['type' => 'integer'], 1000],
    'a literal type beside an extension member' => [['type' => 'integer', 'x-docuccino' => ['id' => 'x']], 1000],
    'a nullable type array' => [['type' => ['integer', 'null']], 1000],
    'an anyOf' => [['anyOf' => [['type' => 'integer'], ['type' => 'null']]], 1000],
    'a oneOf' => [['oneOf' => [['type' => 'integer'], ['type' => 'null']]], 1000],
    'an allOf' => [['allOf' => [['type' => 'integer']]], 1000],
    'a $ref' => [['$ref' => '#/components/schemas/PerPage'], 1000],
    'a $ref hoisted into an allOf beside its siblings' => [
        ['allOf' => [['$ref' => '#/components/schemas/PerPage'], ['maximum' => 100]]], 1000,
    ],
    'a $ref chain' => [['$ref' => '#/components/schemas/PerPageAlias'], 1000],
    'a $ref inside an anyOf' => [['anyOf' => [['$ref' => '#/components/schemas/PerPage'], ['type' => 'null']]], 1000],
    'an enum with no type of its own' => [['enum' => [10, 25, 1000]], 1000],
    'an enum behind a $ref' => [['$ref' => '#/components/schemas/UntypedSizes'], 1000],
]);

it('leaves a string alone where the contract says nothing it can read a type from', function (?array $schema): void {
    expect(ParameterValue::coerce('1000', $schema, contractSchemaDocument()))->toBe('1000');
})->with([
    // A reference the document does not define is not a quiet "no coercion": SchemaCheck cannot
    // resolve it either, so the check fails naming the pointer (ContractCheckerTest pins that).
    'a $ref at a name nothing defines' => [['$ref' => '#/components/schemas/Ghost']],
    // A sibling does not stand in for the half that would not resolve. The node means "whatever Ghost
    // says AND an integer", and half of that is unreadable — so the type is unknown, not `integer`.
    'a $ref at a name nothing defines, beside a type of its own' => [['$ref' => '#/components/schemas/Ghost', 'type' => 'integer']],
    'a draft-07 definitions pointer' => [['$ref' => '#/definitions/PerPage']],
    'a reference into another file' => [['$ref' => 'other.json#/PerPage']],
    'a $ref that composes its way back to itself' => [['$ref' => '#/components/schemas/Cycle']],
    'a composition nested past the depth bound' => [array_reduce(
        range(1, 12),
        static fn (array $carry, int $level): array => ['allOf' => [$carry]],
        ['type' => 'integer'],
    )],
    'a composition keyword that is not a list of schemas' => [['anyOf' => 'integer']],
    'a branch that is not a schema' => [['anyOf' => ['integer', 42]]],
    'an empty schema' => [[]],
    'no schema at all' => [null],
]);

it('leaves a string alone where the contract permits a string, whatever else it permits', function (?array $schema): void {
    // The value already satisfies the contract as it arrived, so converting can only take a pass
    // away: `anyOf: [{integer, minimum: 100}, {string}]` accepts `1000` as sent and rejects it as a
    // number. A union that admits several readings resolves toward the wire.
    expect(ParameterValue::coerce('1000', $schema, contractSchemaDocument()))->toBe('1000');
})->with([
    'an integer-or-string union' => [['anyOf' => [['type' => 'integer'], ['type' => 'string']]]],
    'a multi-type including string' => [['type' => ['integer', 'string']]],
    'an allOf that cannot be satisfied at all' => [['allOf' => [['type' => 'integer'], ['type' => 'string']]]],
    'an enum whose members are of several types' => [['enum' => [10, 'all']]],
]);

it('still refuses a string that is not unambiguously the documented type, behind a reference', function (): void {
    expect(ParameterValue::coerce('abc', ['$ref' => '#/components/schemas/PerPage'], contractSchemaDocument()))->toBe('abc')
        ->and(ParameterValue::coerce('1.5', ['anyOf' => [['type' => 'integer'], ['type' => 'null']]], contractSchemaDocument()))->toBe('1.5')
        ->and(ParameterValue::coerce('yes', ['allOf' => [['type' => 'boolean']]], contractSchemaDocument()))->toBe('yes');
});

it('reads items and properties out of the same resolution the type came from', function (): void {
    $document = contractSchemaDocument();

    expect(ParameterValue::coerce('1,2,3', ['$ref' => '#/components/schemas/SizeList'], $document))->toBe([1, 2, 3])
        ->and(ParameterValue::coerce('4,5', ['allOf' => [['$ref' => '#/components/schemas/SizeList']]], $document))->toBe([4, 5])
        ->and(ParameterValue::coerce(['size' => '7'], ['$ref' => '#/components/schemas/SizeFilter'], $document)->size)->toBe(7);
});
