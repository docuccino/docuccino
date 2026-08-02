<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Integrations\Enum\EnumSchema;
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

it('falls back to case names for an enum it cannot reflect', function (): void {
    $schema = convertEnum(new EnumT('App\\Enums\\Missing', ['Open', 'Closed']));

    expect($schema)->toBe(['type' => 'string', 'enum' => ['Open', 'Closed']]);
});
