<?php

declare(strict_types=1);

use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Document\UirDocument;

/*
 * A composition with one branch and nothing beside it but annotations validates exactly what that branch
 * validates: `allOf` of one schema is that schema, and so are `anyOf` and `oneOf` of one. So wrapping a
 * schema that way, which is how OpenAPI 3.0 has to write a described `$ref`, is no change to what it
 * admits, and the only thing a diff owes is the annotation the wrapper carries.
 */

/**
 * A document whose response and request both carry `$schema` as the `owner` property.
 *
 * @param  array<string, mixed>|bool  $schema
 * @return array<string, mixed>
 */
function loneBranchDocument(string $openapi, array|bool $schema): array
{
    $body = ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['owner' => $schema]]]]];

    return [
        'openapi' => $openapi,
        'info' => ['title' => 'Pets', 'version' => '1'],
        'paths' => ['/pets' => [
            'get' => ['responses' => ['200' => ['description' => 'OK'] + $body]],
            'put' => ['requestBody' => $body, 'responses' => ['204' => ['description' => 'Saved']]],
        ]],
        'components' => ['schemas' => [
            'Owner' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            'Keeper' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'since' => ['type' => 'string']], 'required' => ['since']],
        ]],
    ];
}

/**
 * @param  array<string, mixed>|bool  $old
 * @param  array<string, mixed>|bool  $new
 */
function loneBranchDiff(array|bool $old, array|bool $new, string $oldVersion = '3.1.1', ?string $newVersion = null): Changeset
{
    $read = static fn (string $openapi, array|bool $schema): UirDocument => UirDocument::fromArray(loneBranchDocument($openapi, $schema));

    return (new DocumentDiffer)->diff($read($oldVersion, $old), $read($newVersion ?? $oldVersion, $new));
}

/** @return list<string> each change as its operation, verdict, code and property-relative pointer, sorted */
function loneBranchChanges(Changeset $changeset): array
{
    $changes = array_map(
        static fn ($change): string => strtok($change->path, ' ').' '.($change->breaking ? 'breaking ' : '').$change->code.' '.(string) strstr($change->path, 'owner'),
        $changeset->changes,
    );
    sort($changes);

    return $changes;
}

$owner = ['$ref' => '#/components/schemas/Owner'];

/** @return list<string> one annotation edit to `owner`, on the response and on the request */
function loneBranchDescribed(string $keyword = 'description'): array
{
    return ['GET schema.annotation-changed owner.'.$keyword, 'PUT schema.annotation-changed owner.'.$keyword];
}

it('reads a described reference wrapped in a one-branch `allOf` as the reference, reporting only the description', function (string $openapi) use ($owner): void {
    $wrapped = ['allOf' => [$owner], 'description' => 'Who owns the pet'];

    // The description is the one thing that moved: never a branch arriving, nor the type going.
    expect(loneBranchChanges(loneBranchDiff($owner, $wrapped, $openapi)))->toBe(loneBranchDescribed())
        ->and(loneBranchChanges(loneBranchDiff($wrapped, $owner, $openapi)))->toBe(loneBranchDescribed());
})->with(['3.1.1', '3.0.3']);

it('finds no change where the wrapper and the reference say the same thing', function () use ($owner): void {
    $described = ['description' => 'Who owns the pet'];

    expect(loneBranchDiff($owner + $described, ['allOf' => [$owner]] + $described)->changes)->toBe([])
        ->and(loneBranchDiff(['allOf' => [$owner]] + $described, $owner + $described)->changes)->toBe([]);
});

it('reports only the description between a newer document describing a reference and a 3.0 one that does not', function () use ($owner): void {
    // The newer side's description can only be written at 3.0 by wrapping the reference, which is how the
    // two are compared.
    $described = $owner + ['description' => 'Who owns the pet'];

    foreach (['3.1.1', '3.2.0'] as $newer) {
        expect(loneBranchChanges(loneBranchDiff($owner, $described, '3.0.3', $newer)))->toBe(loneBranchDescribed())
            ->and(loneBranchChanges(loneBranchDiff($described, $owner, $newer, '3.0.3')))->toBe(loneBranchDescribed());
    }
});

it('reads a one-branch `anyOf` or `oneOf` as its branch, since one of one is that one', function (string $keyword) use ($owner): void {
    expect(loneBranchDiff($owner, [$keyword => [$owner]])->changes)->toBe([])
        ->and(loneBranchDiff([$keyword => [$owner]], $owner)->changes)->toBe([])
        ->and(loneBranchDiff(['allOf' => [$owner]], [$keyword => [$owner]])->changes)->toBe([]);
})->with(['anyOf', 'oneOf']);

it('reads an inline branch the same way, and still reports what moved inside it', function (): void {
    $name = ['type' => 'string', 'maxLength' => 40];

    expect(loneBranchChanges(loneBranchDiff($name, ['allOf' => [$name], 'title' => 'Name'])))->toBe(loneBranchDescribed('title'));

    // A tighter bound inside the wrapper is the narrowing it would be anywhere, gating both sides.
    expect(loneBranchChanges(loneBranchDiff($name, ['allOf' => [['maxLength' => 20] + $name], 'title' => 'Name'])))->toBe([
        'GET breaking schema.refinement-narrowed owner.maxLength',
        'GET schema.annotation-changed owner.title',
        'PUT breaking schema.refinement-narrowed owner.maxLength',
        'PUT schema.annotation-changed owner.title',
    ]);
});

it('compares a wrapper round a different component as that component', function () use ($owner): void {
    // Repointing is reported as it is between two bare references, and `Keeper` requiring a member
    // `Owner` never had gates a request while being only a report on a response.
    expect(loneBranchChanges(loneBranchDiff($owner, ['allOf' => [['$ref' => '#/components/schemas/Keeper']], 'description' => 'Who keeps it'])))->toBe([
        'GET schema.annotation-changed owner.description',
        'GET schema.property-added owner.properties.since',
        'GET schema.ref-changed owner.$ref',
        'GET schema.required-added owner.required',
        'PUT breaking schema.required-added owner.required',
        'PUT schema.annotation-changed owner.description',
        'PUT schema.property-added owner.properties.since',
        'PUT schema.ref-changed owner.$ref',
    ]);
});

it('still reads a one-branch composition with an asserting sibling as the refinement it is', function () use ($owner): void {
    // `maxProperties` beside the branch constrains the value, so the wrapper is not its branch any more.
    $changes = loneBranchDiff($owner, ['allOf' => [$owner], 'maxProperties' => 1]);

    expect(loneBranchChanges($changes))->toContain('GET breaking schema.refinement-narrowed owner.maxProperties')
        ->toContain('PUT breaking schema.refinement-narrowed owner.maxProperties');
});

it('compares two compositions under one keyword branch by branch', function () use ($owner): void {
    // Both sides spell an `allOf`, so the branches pair and only what differs beside them is reported.
    expect(loneBranchChanges(loneBranchDiff(['allOf' => [$owner], 'description' => 'Who owns it'], ['allOf' => [$owner], 'maxProperties' => 1])))->toBe([
        'GET breaking schema.refinement-narrowed owner.maxProperties',
        'GET schema.annotation-changed owner.description',
        'PUT breaking schema.refinement-narrowed owner.maxProperties',
        'PUT schema.annotation-changed owner.description',
    ]);

    // A second branch is a constraint arriving, wrapper or not.
    expect(loneBranchChanges(loneBranchDiff(['allOf' => [$owner], 'description' => 'Who owns it'], ['allOf' => [$owner, ['minProperties' => 1]], 'description' => 'Who owns it'])))->toBe([
        'GET breaking schema.all-of-branch-added owner.allOf.1',
        'PUT breaking schema.all-of-branch-added owner.allOf.1',
    ]);
});

it('reads a one-branch composition of `false` as `false`', function (): void {
    expect(loneBranchDiff(false, ['allOf' => [false]])->changes)->toBe([])
        ->and(loneBranchDiff(['oneOf' => [false], 'description' => 'Never sent'], false)->changes)->toBe([]);
});

it('pairs a wrapped branch of a union with the component it wraps', function () use ($owner): void {
    // A union branch that wraps `Owner` IS `Owner`, so it pairs with the bare one rather than reading as
    // one branch gone and another arrived, and the inline branches left over still pair as an edit.
    $before = ['oneOf' => [$owner, ['type' => 'string']]];
    $after = ['oneOf' => [['allOf' => [$owner], 'description' => 'Who owns it'], ['type' => 'integer']]];

    expect(loneBranchChanges(loneBranchDiff($before, $after)))->toBe([
        'GET breaking schema.type-changed owner.oneOf.1.type',
        'GET schema.annotation-changed owner.oneOf.0.description',
        'PUT breaking schema.type-changed owner.oneOf.1.type',
        'PUT schema.annotation-changed owner.oneOf.0.description',
    ]);
});

it('never reads a one-slot `prefixItems` as its slot, which constrains an element and not the value', function (): void {
    $codes = array_map(
        static fn ($change): string => $change->code,
        loneBranchDiff(['type' => 'string'], ['prefixItems' => [['type' => 'string']]])->changes,
    );

    expect($codes)->toContain('schema.type-removed');
});
