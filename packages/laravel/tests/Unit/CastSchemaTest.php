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
    'array' => ['array', ['type' => 'array']],
    'collection' => ['collection', ['type' => 'array']],
    'object' => ['object', ['type' => 'object']],
]);

it('strips cast parameters and is case-insensitive on the base', function (): void {
    // Parameters after the first colon (`datetime:Y-m-d`, `decimal:2`) are ignored — only the base
    // decides the fragment — and the base is matched case-insensitively.
    expect(CastSchema::forCast('datetime:Y-m-d H:i:s'))->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and(CastSchema::forCast('decimal:2'))->toBe(['type' => 'string'])
        ->and(CastSchema::forCast('DateTime'))->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and(CastSchema::forCast('BOOLEAN'))->toBe(['type' => 'boolean']);

    // Compound `encrypted:array|collection|object` casts fold to their `encrypted` base (a string) —
    // the colon-stripping means only the base is ever matched. Documented so the behaviour is pinned.
    expect(CastSchema::forCast('encrypted:array'))->toBe(['type' => 'string'])
        ->and(CastSchema::forCast('encrypted:collection'))->toBe(['type' => 'string'])
        ->and(CastSchema::forCast('encrypted:object'))->toBe(['type' => 'string']);
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
