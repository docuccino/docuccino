<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine (out-of-process) coverage for the inference-dependent halves of the Phase-4
 * integrations, so the type-recovery those integrations lean on is exercised by the ACTUAL
 * PHPStan/Larastan engine — not only the deterministic stub. Complements the in-process unit tests
 * (which drive the mappers) and the existing JsonResponse status/payload real-engine smoke.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('recovers an API resource toArray shape as a constant array shape', function (): void {
    // The real engine analyses UserResource::toArray (@mixin User) into an array{id, name, email}.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Resources/UserResource.php',
        'App\\Http\\Resources\\UserResource',
        'toArray',
    ));

    $type = $analysis->returns[0]->type ?? null;
    expect($type)->toBeInstanceOf(ArrayShapeT::class);

    $keys = array_map(static fn ($field): string => (string) $field->key, $type->fields);
    expect($keys)->toBe(['id', 'name', 'email']);
})->group('fixture');

it('recovers real Eloquent model columns (incl. a cast-target type) via classMetadata', function (): void {
    // The real engine reflects App\Models\CatalogItem's typed public column properties — the same
    // classMetadata path ModelSchema consumes to build a model's object schema.
    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Models\\CatalogItem'));

    $byName = [];
    foreach ($metadata->properties as $property) {
        $byName[$property->name] = $property->type;
    }

    // The declared columns are all recovered (framework bookkeeping props may also be present).
    expect($byName)->toHaveKeys(['id', 'sku', 'is_active', 'description']);

    // Precise column types by reflection: id is an int, the boolean-cast column is a bool, and the
    // nullable column is a string|null union.
    expect($byName['id']->canonicalKey())->toBe(ScalarT::int()->canonicalKey())
        ->and($byName['is_active']->canonicalKey())->toBe(ScalarT::bool()->canonicalKey())
        ->and($byName['description'])->toBeInstanceOf(UnionT::class)
        ->and(array_filter($byName['description']->members, static fn ($m): bool => $m instanceof NullT))->not->toBeEmpty();
})->group('fixture');

it('recovers a real Data class shape via classMetadata (property types, not a stub)', function (): void {
    // The real engine reflects App\Data\ArticleData's typed public properties.
    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Data\\ArticleData'));

    $byName = [];
    foreach ($metadata->properties as $property) {
        $byName[$property->name] = $property->type;
    }

    expect(array_keys($byName))->toBe(['id', 'title', 'subtitle']);

    // Precise types recovered by reflection: id is an integer, subtitle is nullable.
    expect($byName['id']->canonicalKey())->toBe(ScalarT::int()->canonicalKey());

    $subtitle = $byName['subtitle'];
    expect($subtitle)->toBeInstanceOf(UnionT::class)
        ->and(array_filter($subtitle->members, static fn ($m): bool => $m instanceof NullT))->not->toBeEmpty();
})->group('fixture');
