<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Eloquent\CastSchema;
use Workbench\App\Enums\WidgetStatus;

/**
 * Exhaustive coverage of the `$casts` → JSON Schema table (test-coverage standard: a mapping table
 * is tested over EVERY entry plus the unknown-entry degradation). Each cast base maps to a fixed
 * schema fragment; a caster the table does not know returns null so the column keeps its inferred
 * type; enum casts route away through `isEnum()`.
 */
it('maps every known cast base to its schema fragment', function (string $cast, array $expected): void {
    expect(CastSchema::forCast($cast))->toBe($expected);
})->with([
    'datetime' => ['datetime', ['type' => 'string', 'format' => 'date-time']],
    'immutable_datetime' => ['immutable_datetime', ['type' => 'string', 'format' => 'date-time']],
    'custom_datetime' => ['custom_datetime', ['type' => 'string', 'format' => 'date-time']],
    'date' => ['date', ['type' => 'string', 'format' => 'date']],
    'immutable_date' => ['immutable_date', ['type' => 'string', 'format' => 'date']],
    'timestamp' => ['timestamp', ['type' => 'integer']],
    'boolean' => ['boolean', ['type' => 'boolean']],
    'bool' => ['bool', ['type' => 'boolean']],
    'integer' => ['integer', ['type' => 'integer']],
    'int' => ['int', ['type' => 'integer']],
    'real' => ['real', ['type' => 'number']],
    'float' => ['float', ['type' => 'number']],
    'double' => ['double', ['type' => 'number']],
    'decimal' => ['decimal', ['type' => 'string']],
    'string' => ['string', ['type' => 'string']],
    'encrypted' => ['encrypted', ['type' => 'string']],
    'hashed' => ['hashed', ['type' => 'string']],
    'array' => ['array', ['type' => ['array', 'object']]],
    'collection' => ['collection', ['type' => ['array', 'object']]],
    'json' => ['json', ['type' => ['array', 'object']]],
    'object' => ['object', ['type' => 'object']],
]);

it('strips decimal/plain parameters and is case-insensitive on the base', function (): void {
    // A bare parameter after the colon (`decimal:2`) is ignored, and the base is case-insensitive.
    expect(CastSchema::forCast('decimal:2'))->toBe(['type' => 'string'])
        ->and(CastSchema::forCast('DateTime'))->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and(CastSchema::forCast('BOOLEAN'))->toBe(['type' => 'boolean'])
        ->and(CastSchema::forCast('json:unicode'))->toBe(['type' => ['array', 'object']]);
});

it('maps a datetime:FORMAT to an honest format claim', function (string $cast, array $expected): void {
    expect(CastSchema::forCast($cast))->toBe($expected);
})->with([
    // Default and ISO date-time forms → date-time.
    'default datetime' => ['datetime', ['type' => 'string', 'format' => 'date-time']],
    'ISO atom' => ['datetime:Y-m-d\\TH:i:sP', ['type' => 'string', 'format' => 'date-time']],
    'space-separated ISO' => ['datetime:Y-m-d H:i:s', ['type' => 'string', 'format' => 'date-time']],
    // ISO date-only → date.
    'date-only' => ['datetime:Y-m-d', ['type' => 'string', 'format' => 'date']],
    // A bespoke non-ISO format is neither date nor date-time: a plain string with the format noted.
    'custom format' => ['datetime:d/m/Y', ['type' => 'string', 'description' => 'Serialized using the date format "d/m/Y".']],
]);

it('decrypts-then-casts an encrypted:<inner> compound to the inner shape', function (): void {
    // encrypted:array/collection/json serialise as the decoded JSON value (object or array), NOT a
    // string; encrypted:object as an object; bare encrypted stays a string.
    expect(CastSchema::forCast('encrypted:array'))->toBe(['type' => ['array', 'object']])
        ->and(CastSchema::forCast('encrypted:collection'))->toBe(['type' => ['array', 'object']])
        ->and(CastSchema::forCast('encrypted:json'))->toBe(['type' => ['array', 'object']])
        ->and(CastSchema::forCast('encrypted:object'))->toBe(['type' => 'object'])
        ->and(CastSchema::forCast('encrypted'))->toBe(['type' => 'string']);
});

it('returns null for a cast the table does not know so the column keeps its inferred type', function (string $cast): void {
    expect(CastSchema::forCast($cast))->toBeNull();
})->with([
    'a custom caster class' => ['App\\Casts\\Money'],
    'the AsStringable helper' => ['Illuminate\\Database\\Eloquent\\Casts\\AsStringable'],
    'an unknown keyword' => ['nonsense'],
    'an empty string' => [''],
]);

it('recognises a backed-enum cast base, ignoring parameters and non-enums', function (): void {
    expect(CastSchema::isEnum(WidgetStatus::class))->toBeTrue()
        // The enum-cast class-string may carry a `:default` parameter Laravel strips likewise.
        ->and(CastSchema::isEnum(WidgetStatus::class.':draft'))->toBeTrue()
        ->and(CastSchema::isEnum('datetime'))->toBeFalse()
        ->and(CastSchema::isEnum('App\\Casts\\Money'))->toBeFalse();
});
