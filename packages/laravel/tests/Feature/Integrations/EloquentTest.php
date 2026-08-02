<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use Docuccino\Laravel\Integrations\Eloquent\ModelSchema;
use Docuccino\Laravel\Integrations\Enum\EnumSchema;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Gadget;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Widget;

/**
 * The Eloquent model schema integration (Phase 4): columns (from the engine) refined by the model's
 * visible/hidden/appends + class-level #[Hidden], with casts fixing datetime formats and routing enum
 * casts through the Enum integration.
 */
function eloquentEngine(): StubTypeEngine
{
    return new StubTypeEngine(classes: [
        Widget::class => new ClassMetadata(Widget::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('name', ScalarT::string()),
            new PropertyMetadata('password', ScalarT::string()),
            new PropertyMetadata('token', ScalarT::string()),
            new PropertyMetadata('created_at', UnionT::of([ScalarT::string(), new NullT])),
            // is_active is typed string by the engine, but the boolean cast wins.
            new PropertyMetadata('is_active', ScalarT::string()),
            new PropertyMetadata('status', ScalarT::string()),
            new PropertyMetadata('meta', ScalarT::string()),
        ]),
        Gadget::class => new ClassMetadata(Gadget::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('name', ScalarT::string()),
            new PropertyMetadata('secret', ScalarT::string()),
        ]),
    ]);
}

function modelSchema(ClassT $type): array
{
    $components = new ComponentRegistry;
    $converter = new SchemaConverter([new ModelSchema, new EnumSchema, ...DefaultTypeMappers::all()], eloquentEngine(), $components);
    $converter->toSchema($type);

    return $components->schemas();
}

it('builds a model schema honouring hidden, appends, and casts', function (): void {
    $widget = modelSchema(new ClassT(Widget::class))['Widget'];

    // password ($hidden) and token (class-level #[Hidden]) are dropped; display_name ($appends) added.
    expect(array_keys($widget['properties']))
        ->toBe(['id', 'name', 'created_at', 'is_active', 'status', 'meta', 'display_name']);

    // datetime cast → date-time format; boolean cast overrides the engine's string type; array cast.
    expect($widget['properties']['created_at'])->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and($widget['properties']['is_active'])->toBe(['type' => 'boolean'])
        ->and($widget['properties']['meta'])->toBe(['type' => 'array']);

    // enum cast routes through the Enum integration (backing values + case descriptions).
    expect($widget['properties']['status']['enum'])->toBe(['draft', 'published', 'archived'])
        ->and($widget['properties']['status'])->toHaveKey('x-enumDescriptions');

    // nullable created_at and the appended accessor are non-required.
    expect($widget['required'])->toBe(['id', 'name', 'is_active', 'status', 'meta']);
});

it('applies a $visible allow-list', function (): void {
    $gadget = modelSchema(new ClassT(Gadget::class))['Gadget'];

    expect(array_keys($gadget['properties']))->toBe(['id', 'name'])
        ->and($gadget['properties'])->not->toHaveKey('secret');
});

it('reflects model facts without instantiating', function (): void {
    $facts = (new EloquentModelReflector)->facts(Widget::class);

    expect($facts['hidden'])->toBe(['password'])
        ->and($facts['classHidden'])->toBe(['token'])
        ->and($facts['appends'])->toBe(['display_name'])
        ->and($facts['casts'])->toHaveKey('created_at')
        ->and(EloquentModelReflector::isModel(Widget::class))->toBeTrue()
        ->and(EloquentModelReflector::isModel('Illuminate\\Database\\Eloquent\\Model'))->toBeFalse();
});
