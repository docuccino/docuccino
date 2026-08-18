<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Attaches a mock hint — `x-docuccino.mock` — to the schema a property publishes: `$faker` is the
 * expression a mock server evaluates, `$seedGroup` names the properties whose values it should
 * correlate. The hint is metadata for tooling, never a value: nothing generated from it reaches the
 * document.
 *
 * On a property it applies to that property. On a class it needs `$property`, naming a published
 * member — the form for an Eloquent column or a validated field, which has no PHP property to
 * carry it — and repeats for as many members as you need.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Mock
{
    /**
     * @param  string|null  $faker  the expression a mock server evaluates for this property
     * @param  string|null  $seedGroup  a name shared by properties whose mock values should correlate
     * @param  string|null  $property  the published member this hint applies to; required on a class
     */
    public function __construct(
        public ?string $faker = null,
        public ?string $seedGroup = null,
        public ?string $property = null,
    ) {}
}
