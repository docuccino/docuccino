<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Validation\AdoptedUnion;

/*
 * A declared union written over the object the rules split by a tag. Which declarations adopt the split,
 * and what each tag value's refinement carries, read off the bodies alone — the component bodies the
 * declaration names, and the branches the rules left in place.
 */

$member = static fn (string $kind, array $properties, array $required = []): array => [
    'type' => 'object',
    'properties' => ['kind' => ['type' => 'string', 'const' => $kind]] + $properties,
    'required' => ['kind', ...$required],
];

$schemas = static fn (array $members, string $union = 'Shape'): array => [
    $union => ['anyOf' => array_map(static fn (string $name): array => ['$ref' => '#/components/schemas/'.$name], array_keys($members))],
] + $members;

$split = static fn (array ...$branches): array => ['anyOf' => [...$branches, ['type' => 'null']], 'description' => 'From the rules.'];

it('refines the declared union by tag value with what only the rules state', function () use ($member, $schemas, $split): void {
    $adopted = AdoptedUnion::over(
        ['$ref' => '#/components/schemas/Shape'],
        $split(
            $member('round', ['radius' => ['type' => 'number', 'minimum' => 0, 'description' => 'Rule prose.']], ['radius']),
            $member('square', ['side' => ['type' => 'integer', 'maximum' => 9], 'label' => ['type' => 'string', 'maxLength' => 3, 'example' => 'abc']], ['side', 'label']),
        ),
        $schemas([
            'Round' => $member('round', ['radius' => ['type' => 'number']], ['radius']),
            'Square' => $member('square', ['side' => ['type' => 'integer', 'maximum' => 5]], ['side']),
        ]),
        'shape',
    );

    expect($adopted?->schema)->toEqual([
        // The rules' own annotations stand where the declaration says nothing of its own.
        'description' => 'From the rules.',
        '$ref' => '#/components/schemas/Shape',
        'anyOf' => [
            // A bound the declared member does not state; its prose is the declaration's to write.
            ['properties' => ['kind' => ['const' => 'round'], 'radius' => ['minimum' => 0]]],
            // The declared `maximum` stands; a member the declaration lacks is the rules' to describe, and
            // one they require it leaves optional is required.
            ['properties' => ['kind' => ['const' => 'square'], 'label' => ['type' => 'string', 'maxLength' => 3]], 'required' => ['label']],
        ],
    ])
        // The declaration admits no null where the rules do.
        ->and($adopted?->wider)->toBe(['`shape`, which the rules let be null']);
});

it('publishes the declared reference alone where the rules state nothing it does not', function () use ($member, $schemas, $split): void {
    $adopted = AdoptedUnion::over(
        ['anyOf' => [['$ref' => '#/components/schemas/Shape'], ['type' => 'null']], 'description' => 'Declared.'],
        $split($member('round', []), $member('square', [])),
        $schemas(['Round' => $member('round', []), 'Square' => $member('square', [])]),
        'shape',
    );

    expect($adopted?->schema)->toEqual(['description' => 'Declared.', 'anyOf' => [['$ref' => '#/components/schemas/Shape'], ['type' => 'null']]])
        ->and($adopted?->wider)->toBe([]);
});

it('notes each member where the rules accept a type the declaration does not', function () use ($member, $schemas, $split): void {
    $adopted = AdoptedUnion::over(
        ['anyOf' => [['$ref' => '#/components/schemas/Shape'], ['type' => 'null']]],
        $split(
            $member('round', ['radius' => ['type' => 'number']]),
            $member('square', ['side' => ['type' => 'integer'], 'tags' => ['type' => 'array', 'items' => ['type' => ['string', 'integer']]]]),
        ),
        $schemas([
            'Round' => $member('round', ['radius' => ['type' => 'integer']]),
            // A number admits every integer, so a declared number under an integer rule is no narrowing.
            'Square' => $member('square', ['side' => ['type' => 'number'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']]]),
        ]),
        'shape',
    );

    expect($adopted?->wider)->toBe(['`shape.radius` where `kind` is round', '`shape.tags.*` where `kind` is square']);
});

it('reads a declared member through its reference to refine what is inside it', function () use ($member, $schemas, $split): void {
    $adopted = AdoptedUnion::over(
        ['$ref' => '#/components/schemas/Shape'],
        $split(
            $member('round', ['centre' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer', 'minimum' => 0]]]]),
            $member('square', []),
        ),
        $schemas([
            'Round' => $member('round', ['centre' => ['$ref' => '#/components/schemas/Point']]),
            'Square' => $member('square', []),
        ]) + ['Point' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]]],
        'shape',
    );

    expect($adopted?->schema['anyOf'][0] ?? null)->toBe(['properties' => ['kind' => ['const' => 'round'], 'centre' => ['properties' => ['x' => ['minimum' => 0]]]]]);
});

it('leaves to the declared-shape rule what it cannot adopt', function (array $declared, array $standing, array $schemas): void {
    expect(AdoptedUnion::over($declared, $standing, $schemas, 'shape'))->toBeNull();
})->with(function () use ($member, $schemas, $split): array {
    $two = $schemas(['Round' => $member('round', []), 'Square' => $member('square', [])]);
    $branches = $split($member('round', []), $member('square', []));

    return [
        'a declared scalar' => [['type' => 'string'], $branches, $two],
        'a declared reference to an object' => [['$ref' => '#/components/schemas/Round'], $branches, $two],
        'a reference to nothing registered' => [['$ref' => '#/components/schemas/Missing'], $branches, $two],
        'a union written inline' => [['anyOf' => [['$ref' => '#/components/schemas/Round'], ['$ref' => '#/components/schemas/Square']]], $branches, $two],
        'a union of one' => [['$ref' => '#/components/schemas/Shape'], $branches, $schemas(['Round' => $member('round', [])])],
        'a union with an inline member' => [['$ref' => '#/components/schemas/Shape'], $branches, ['Shape' => ['anyOf' => [['$ref' => '#/components/schemas/Round'], ['type' => 'object']]], 'Round' => $member('round', [])]],
        'an object the rules left merged' => [['$ref' => '#/components/schemas/Shape'], ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]], $two],
        'branches told apart by nothing' => [['$ref' => '#/components/schemas/Shape'], ['anyOf' => [['properties' => ['a' => ['type' => 'string']]], ['properties' => ['b' => ['type' => 'string']]]]], $two],
    ];
});

it('says why a declared tagged union does not match the rules\' one', function (array $members, string $reason) use ($schemas, $split, $member): void {
    $adopted = AdoptedUnion::over(['$ref' => '#/components/schemas/Shape'], $split($member('round', []), $member('square', [])), $schemas($members), 'shape');

    expect($adopted?->schema)->toBeNull()
        ->and($adopted?->mismatch)->toBe($reason);
})->with(function () use ($member): array {
    $typed = static fn (string $type, array $properties = []): array => [
        'type' => 'object',
        'properties' => ['type' => ['type' => 'string', 'const' => $type]] + $properties,
        'required' => ['type'],
    ];

    return [
        'other values' => [
            ['Round' => $member('round', []), 'Oval' => $member('oval', [])],
            'the rules accept one shape per `kind` (round, square), and the declared type is told apart by `kind` (oval, round)',
        ],
        'another tag' => [
            ['Round' => $typed('round'), 'Square' => $typed('square')],
            'the rules accept one shape per `kind` (round, square), and the declared type is told apart by `type` (round, square)',
        ],
        'a value shared' => [
            ['Round' => $member('round', []), 'Square' => $member('round', [])],
            'the rules accept one shape per `kind` (round, square), and the declared type is told apart by no property its members each fix to a value of their own',
        ],
    ];
});
