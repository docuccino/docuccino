<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\BuiltIn\EnumSchema;
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
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Blank;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Gadget;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Invoice;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Ledger;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Widget;
use Workbench\App\Enums\WidgetStatus;

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
        Vault::class => new ClassMetadata(Vault::class, [
            new PropertyMetadata('id', ScalarT::string()),
            new PropertyMetadata('label', ScalarT::string()),
        ]),
        Invoice::class => new ClassMetadata(Invoice::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('amount', ScalarT::int()),
            new PropertyMetadata('issued_at', UnionT::of([ScalarT::string(), new NullT])),
            new PropertyMetadata('meta', ScalarT::string()),
            new PropertyMetadata('status', ScalarT::string()),
        ]),
    ]);
}

function modelSchema(ClassT $type): array
{
    return modelRegistry($type)->schemas();
}

function modelRegistry(ClassT $type): ComponentRegistry
{
    $components = new ComponentRegistry;
    $converter = new SchemaConverter([new ModelSchema, new EnumSchema, ...DefaultTypeMappers::all()], eloquentEngine(), $components);
    $converter->toSchema($type);

    return $components;
}

it('builds a model schema honouring hidden, appends, and casts', function (): void {
    $widget = modelSchema(new ClassT(Widget::class))['Widget'];

    // password ($hidden) and token (class-level #[Hidden]) are dropped; display_name ($appends) added;
    // updated_at is synthesised from the model's default timestamps (created_at is already a cast).
    expect(array_keys($widget['properties']))
        ->toBe(['id', 'name', 'created_at', 'is_active', 'status', 'meta', 'updated_at', 'display_name']);

    // datetime cast → date-time format, widened to admit null on the nullable column; boolean cast
    // overrides the engine's string type; array cast admits a JSON object or array.
    expect($widget['properties']['created_at'])->toBe(['type' => ['string', 'null'], 'format' => 'date-time'])
        ->and($widget['properties']['is_active'])->toBe(['type' => 'boolean'])
        ->and($widget['properties']['meta'])->toBe(['type' => ['array', 'object']]);

    // enum cast routes through the Enum integration (backing values + case descriptions).
    expect($widget['properties']['status']['enum'])->toBe(['draft', 'published', 'archived'])
        ->and($widget['properties']['status'])->toHaveKey('x-enumDescriptions');

    // Every declared column is present in the payload, so all are required — a nullable column
    // (created_at) is required with a null-admitting type. The appended accessor stays optional.
    expect($widget['required'])->toBe(['id', 'name', 'created_at', 'is_active', 'status', 'meta', 'updated_at']);
});

it('synthesises timestamps + soft-delete columns and a uuid primary key', function (): void {
    $vault = modelSchema(new ClassT(Vault::class))['Vault'];

    // HasUuids overrides the key column to a string uuid; timestamps + SoftDeletes inject the columns
    // Laravel serialises for a persisted, soft-deletable model.
    expect($vault['properties']['id'])->toBe(['type' => 'string', 'format' => 'uuid'])
        ->and($vault['properties']['created_at'])->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and($vault['properties']['updated_at'])->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and($vault['properties']['deleted_at'])->toBe(['type' => ['string', 'null'], 'format' => 'date-time'])
        ->and($vault['required'])->toBe(['id', 'label', 'created_at', 'updated_at', 'deleted_at']);
});

it('reflects timestamps, soft-delete, and primary-key facts', function (): void {
    $facts = (new EloquentModelReflector)->facts(Vault::class);

    expect($facts['timestamps'])->toBeTrue()
        ->and($facts['softDeletes'])->toBeTrue()
        ->and($facts['keyName'])->toBe('id')
        ->and($facts['keySchema'])->toBe(['type' => 'string', 'format' => 'uuid']);

    // A plain model has timestamps on by default but no soft-deletes and an integer key.
    $widgetFacts = (new EloquentModelReflector)->facts(Widget::class);
    expect($widgetFacts['softDeletes'])->toBeFalse()
        ->and($widgetFacts['keySchema'])->toBe(['type' => 'integer']);
});

it('reads the casts() method (Laravel 11+) and applies its casts to columns', function (): void {
    $facts = (new EloquentModelReflector)->facts(Invoice::class);

    // The casts() method's literal return is recovered — string casts and the enum ::class cast.
    expect($facts['casts'])->toBe([
        'issued_at' => 'datetime',
        'meta' => 'array',
        'status' => WidgetStatus::class,
    ]);

    $invoice = modelSchema(new ClassT(Invoice::class))['Invoice'];

    // The recovered casts refine the columns: datetime (nullable), array→object|array, enum values.
    expect($invoice['properties']['issued_at'])->toBe(['type' => ['string', 'null'], 'format' => 'date-time'])
        ->and($invoice['properties']['meta'])->toBe(['type' => ['array', 'object']])
        ->and($invoice['properties']['status']['enum'])->toBe(['draft', 'published', 'archived']);
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

it('reflects the floor sources ($fillable, $dates) alongside casts', function (): void {
    $facts = (new EloquentModelReflector)->facts(Ledger::class);

    expect($facts['fillable'])->toBe(['reference', 'amount', 'notes'])
        ->and($facts['dates'])->toBe(['posted_at'])
        ->and($facts['casts'])->toBe(['amount' => 'integer', 'secret' => 'string']);
});

it('builds the column universe from the floor sources when the engine reports no columns', function (): void {
    // Ledger has no @property docblock, so eloquentEngine() reports no columns for it: the whole
    // schema comes from the floor union (casts keys, $dates, $fillable), with $hidden still filtering.
    $ledger = modelSchema(new ClassT(Ledger::class))['Ledger'];

    // Order: casts keys, then $dates, then $fillable-only names. `secret` ($hidden) is dropped.
    expect(array_keys($ledger['properties']))->toBe(['amount', 'posted_at', 'reference', 'notes'])
        ->and($ledger['properties'])->not->toHaveKey('secret');

    // A cast key is typed by its cast; a $dates entry is a date-time; a $fillable-only name is a
    // permissive `{}` at lowered confidence.
    expect($ledger['properties']['amount'])->toBe(['type' => 'integer'])
        ->and($ledger['properties']['posted_at'])->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and($ledger['properties']['reference'])->toBe([])
        ->and($ledger['properties']['notes'])->toBe([]);

    // Cast/date floor columns serialise (required); the untyped permissive ones stay optional.
    expect($ledger['required'])->toBe(['amount', 'posted_at']);
});

it('keeps the bare-object behaviour but raises an info diagnostic for an undocumented model', function (): void {
    $registry = modelRegistry(new ClassT(Blank::class));

    expect($registry->schemas()['Blank'])->toBe(['type' => 'object', 'properties' => []]);

    $codes = array_map(static fn ($d): string => $d->code, $registry->diagnostics());
    expect($codes)->toContain('eloquent.no-columns');
});
