<?php

declare(strict_types=1);

use Docuccino\Core\Contract\SchemaCheck;

/*
 * A check hands the validator its subject and the component schemas the subject can reach, not every
 * component the document declares: the validator walks everything it is handed before validating, so the
 * whole of `components/schemas` made each check cost the document. These hold both halves — what the
 * validator is handed, and that a violation deep inside a reached component is still found for every
 * spelling of the reference that reaches it.
 */

it('hands the validator only the components its subject reaches, transitively', function (): void {
    $recorder = recordingSchemaValidators();
    $check = new SchemaCheck(reachableDefsDocument(['$ref' => '#/components/schemas/Middle']), $recorder->factory());

    $check->check((object) ['leaf' => (object) ['n' => 1]], reachableDefsSubject(), 'the body');

    expect(array_keys(get_object_vars($recorder->roots[0]->{'$defs'})))->toBe(['Middle', 'Leaf']);
});

it('hands a subject that references nothing no components at all', function (): void {
    $recorder = recordingSchemaValidators();
    $check = new SchemaCheck(reachableDefsDocument(['type' => 'object']), $recorder->factory());

    $check->check((object) [], reachableDefsSubject(), 'the body');

    expect(property_exists($recorder->roots[0], '$defs'))->toBeFalse();
});

it('hands every component over where the subject names one some way a pointer cannot say', function (): void {
    $recorder = recordingSchemaValidators();
    $check = new SchemaCheck(
        reachableDefsDocument(['$ref' => '#leaf'], ['Anchored' => ['$anchor' => 'leaf', 'type' => 'integer']]),
        $recorder->factory(),
    );

    $violations = $check->check('not an integer', reachableDefsSubject(), 'the body');

    expect(array_keys(get_object_vars($recorder->roots[0]->{'$defs'})))->toBe(['Middle', 'Leaf', 'Unreached', 'Anchored'])
        ->and($violations)->toHaveCount(1)
        ->and($violations[0]->schemaPointer)->toBe('/components/schemas/Anchored');
});

dataset('spellings of a reference to Middle', [
    'the component pointer' => ['#/components/schemas/Middle', 'Middle'],
    'a $defs pointer written by hand' => ['#/$defs/Middle', 'Middle'],
    'a percent-encoded $defs' => ['#/%24defs/Middle', 'Middle'],
    'an escaped slash in the name' => ['#/components/schemas/Mid~1dle', 'Mid/dle'],
    'a percent-encoded name' => ['#/components/schemas/Mid%20dle', 'Mid dle'],
]);

it('finds a violation two components deep, however the reference to them is spelled', function (string $ref, string $name): void {
    $index = reachableDefsDocument(['$ref' => $ref], [
        $name => ['type' => 'object', 'properties' => ['leaf' => ['$ref' => '#/components/schemas/Leaf']]],
    ]);

    $violations = (new SchemaCheck($index))->check((object) ['leaf' => (object) ['n' => 'x']], reachableDefsSubject(), 'the body');

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->pointer)->toBe('/leaf/n')
        ->and($violations[0]->schemaPointer)->toBe('/components/schemas/Leaf/properties/n');
})->with('spellings of a reference to Middle');

it('refuses a reference to a component the document does not define, in the words it always did', function (): void {
    $check = new SchemaCheck(reachableDefsDocument(['$ref' => '#/components/schemas/Missing']));

    expect(fn () => $check->check((object) [], reachableDefsSubject(), 'the body'))
        ->toThrow(RuntimeException::class, 'Unresolved reference: /%24defs/Missing');
});

it('keeps a recursive component recursive', function (): void {
    $index = reachableDefsDocument(['$ref' => '#/components/schemas/Node'], [
        'Node' => ['type' => 'object', 'properties' => [
            'value' => ['type' => 'integer'],
            'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Node']],
        ]],
    ]);

    $tree = (object) ['value' => 1, 'children' => [(object) ['value' => 2, 'children' => [(object) ['value' => 'x']]]]];

    $violations = (new SchemaCheck($index))->check($tree, reachableDefsSubject(), 'the body');

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->pointer)->toBe('/children/0/children/0/value');
});
