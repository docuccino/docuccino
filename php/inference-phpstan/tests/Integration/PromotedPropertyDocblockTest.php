<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Where a Data class writes its array generics decides whether they survive. `classMetadata()` reads the
 * constructor's `@param` block; a generic written in the promoted parameter's OWN `@var` — the form a real
 * app reaches for, because that is where the prose describing the member already sits — is not read at
 * all. These tests pin both halves against the real engine, so the working form cannot silently regress
 * and the degraded one cannot quietly stay degraded.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** Property name → resolved type, for a fixture-app class. */
function metadataTypes(string $fqcn): array
{
    $types = [];
    foreach (ClassMetadata::fromArray(FixtureRunner::classMetadata($fqcn))->properties as $property) {
        $types[$property->name] = $property->type;
    }

    return $types;
}

it('types a promoted array property from the constructor @param it was documented with', function (): void {
    // The working form, and the control the fix has to preserve: `@param array<string, mixed> $context`
    // in the constructor block, no `@var` of its own → a real MapT, which emits
    // {"type": "object", "additionalProperties": {}}.
    $context = metadataTypes('App\\Data\\SnapshotData')['context'];

    expect($context)->toBeInstanceOf(MapT::class)
        ->and($context->key)->toEqual(ScalarT::string())
        ->and($context->value)->toBeInstanceOf(UnknownT::class);
})->group('fixture');

it('DEGRADED: drops the generic a promoted property writes in its own @var', function (string $property): void {
    // KNOWN GAP. For a promoted property only the constructor's `@param` is consulted — the property's
    // own docComment is already in hand (its summary and @example are read off it two lines earlier) and
    // then thrown away. The type lands as UnknownT('untyped array'), whose schema has no `type` key at
    // all, so the emitted document shows a description with nothing beside it.
    $types = metadataTypes('App\\Data\\SnapshotData');

    expect($types[$property])->toBeInstanceOf(UnknownT::class)
        ->and($types[$property]->reason)->toBe('untyped array');
})->with([
    // @var array<string, mixed>
    'a map' => ['candidate'],
    // @var array<string, array<string, string|null>>
    'a nested map' => ['theme_data'],
    // @var list<SnapshotFormData> — the item class never reaches components.schemas either
    'a list of Data objects' => ['forms'],
    // @var array<int, string> — see TypeStringParserTest: this parses to a MapT, not a ListT
    'an int-keyed array' => ['permissions'],
    // @phpstan-var list<SnapshotFormData> — see DocBlockReaderTest: the prefixed tag is never matched
    'an analyser-prefixed tag' => ['attachments'],
])->group('fixture');

it('keeps the prose of a property whose type it dropped', function (): void {
    // Why the gap reads as "a description but no type" rather than as a missing property: the same
    // docComment the type was never taken from is read for the summary and the example.
    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Data\\SnapshotData'));

    $permissions = null;
    foreach ($metadata->properties as $property) {
        if ($property->name === 'permissions') {
            $permissions = $property;
        }
    }

    expect($permissions?->summary)->toBe('Flat list of permission strings the candidate held at submit.')
        ->and($permissions?->example)->toBe('["listing.view", "listing.create"]')
        ->and($permissions?->type)->toBeInstanceOf(UnknownT::class);
})->group('fixture');

it('DEGRADED: never consults the generic of a natively-typed DataCollection', function (): void {
    // KNOWN GAP, and independent of the one above: a bare `DataCollection` reflects to a ClassT, which
    // counts as precise, so the docblock is not read EVEN THOUGH this generic is written in the
    // constructor `@param` — the form that works for a native `array`. Fixing the `@var` reader alone
    // leaves this exactly as it is. The item class is lost, and the property emits
    // {"type": "array", "items": []}.
    $factors = metadataTypes('App\\Data\\MfaChallengeData')['mfa_factors'];

    expect($factors)->toBeInstanceOf(ClassT::class)
        ->and($factors->fqcn)->toBe('Spatie\\LaravelData\\DataCollection')
        ->and($factors->typeArgs)->toBe([]);
})->group('fixture');

it('types a natively declared backed-enum property as an EnumT', function (): void {
    // The working half of the enum contrast below: reflection answers with the enum and its cases.
    $status = metadataTypes('App\\Data\\SnapshotFormData')['status'];

    expect($status)->toBeInstanceOf(EnumT::class)
        ->and($status->fqcn)->toBe('App\\Enums\\ListingStatus')
        ->and($status->cases)->toBe(['Open', 'Closed', 'Draft']);
})->group('fixture');

it('DEGRADED: types the SAME enum as a plain class when only a @property tag declares it', function (): void {
    // KNOWN GAP, and two of them. App\Models\Listing documents its magic `status` column the ide-helper
    // way — `@property ListingStatus $status` — which routes through the type-string grammar, whose
    // default arm builds a ClassT without asking whether the name is an enum. So the column documents as
    // an object with the enum's `name`/`value` members instead of the string enum right above.
    //
    // The FQCN is unresolved too: class-level `@property` tags are parsed with no import context, so the
    // short name written in the docblock is kept verbatim and no enum could be reflected from it even
    // once the enum check lands.
    $status = metadataTypes('App\\Models\\Listing')['status'];

    expect($status)->toBeInstanceOf(ClassT::class)
        ->and($status)->not->toBeInstanceOf(EnumT::class)
        ->and($status->fqcn)->toBe('ListingStatus');
})->group('fixture');

it('recovers a request DTO\'s map and list generics, so nothing downstream can blame inference', function (): void {
    // The request-side control for the validation-rule collapse pinned in the adapter's
    // SpatieDataDegradedShapeTest: both generics really are here, in full, before the rule vocabulary
    // gets them.
    $types = metadataTypes('App\\Data\\SaveAnswersData');

    expect($types['answers'])->toBeInstanceOf(UnionT::class)
        ->and(array_filter($types['answers']->members, static fn (DType $m): bool => $m instanceof MapT))->not->toBeEmpty()
        ->and(array_filter($types['answers']->members, static fn (DType $m): bool => $m instanceof NullT))->not->toBeEmpty()
        ->and($types['touched_fields'])->toEqual(new ListT(ScalarT::string()));
})->group('fixture');
