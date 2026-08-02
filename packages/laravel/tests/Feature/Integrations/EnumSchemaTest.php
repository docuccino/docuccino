<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Integrations\Enum\EnumSchema;
use Workbench\App\Enums\WidgetPriority;
use Workbench\App\Enums\WidgetStatus;

/**
 * @param  array<string, mixed>  $representation
 * @return array<string, mixed>
 */
function convertEnum(EnumT $enum, array $representation = []): array
{
    $converter = new SchemaConverter(
        [new EnumSchema, ...DefaultTypeMappers::all()],
        new NullTypeEngine,
        new ComponentRegistry,
        RepresentationPolicy::fromConfig($representation),
    );

    return $converter->toSchema($enum)->schema;
}

it('documents a backed enum by its backing values with case descriptions', function (): void {
    $schema = convertEnum(new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']));

    expect($schema)->toBe([
        'type' => 'string',
        'enum' => ['draft', 'published', 'archived'],
        'x-enumDescriptions' => [
            'draft' => 'Not yet visible to applicants.',
            'published' => 'Live and accepting traffic.',
        ],
    ]);
});

it('emits case names as x-enumNames when the naming policy asks for them', function (): void {
    $schema = convertEnum(
        new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']),
        ['enums' => ['naming' => 'x-enumNames']],
    );

    expect($schema['enum'])->toBe(['draft', 'published', 'archived'])
        ->and($schema['x-enumNames'])->toBe(['Draft', 'Published', 'Archived']);
});

it('documents an int-backed enum with an integer type and integer values', function (): void {
    $schema = convertEnum(new EnumT(WidgetPriority::class, ['Low', 'Normal', 'High']));

    expect($schema)->toBe([
        'type' => 'integer',
        'enum' => [1, 5, 10],
        'x-enumDescriptions' => [
            '1' => 'Handled when idle.',
            '10' => 'Jumps the queue.',
        ],
    ]);
});

it('emits x-enum-varnames when the naming policy asks for that strategy', function (): void {
    $schema = convertEnum(
        new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']),
        ['enums' => ['naming' => 'x-enum-varnames']],
    );

    expect($schema['enum'])->toBe(['draft', 'published', 'archived'])
        ->and($schema['x-enum-varnames'])->toBe(['Draft', 'Published', 'Archived'])
        ->and($schema)->not->toHaveKey('x-enumNames');
});

it('emits no name hints under the default (none) naming strategy', function (): void {
    $schema = convertEnum(new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']));

    expect($schema)->not->toHaveKey('x-enumNames')
        ->and($schema)->not->toHaveKey('x-enum-varnames');
});

it('falls back to case names for an enum it cannot reflect', function (): void {
    $schema = convertEnum(new EnumT('App\\Enums\\Missing', ['Open', 'Closed']));

    expect($schema)->toBe(['type' => 'string', 'enum' => ['Open', 'Closed']]);
});

it('degrades to a plain string schema when no values or case names are known', function (): void {
    // Neither reflectable nor carrying case names — the mapper still yields a valid (low-confidence)
    // string schema rather than an empty or broken one.
    $result = (new EnumSchema)->toSchema(
        new EnumT('App\\Enums\\Unknowable', []),
        new SchemaConverter([new EnumSchema, ...DefaultTypeMappers::all()], new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy),
    );

    expect($result->schema)->toBe(['type' => 'string'])
        ->and($result->confidence)->toBe(0.5);
});
