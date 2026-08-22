<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\QueryBuilder\FilterColumn;
use Docuccino\Laravel\Integrations\QueryBuilder\FilterColumnResolver;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\FilterCastModel;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Locker;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Passcard;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Turnstile;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Waybill;
use Workbench\App\Enums\WidgetStatus;

/**
 * Dataset coverage over the cast → filter-column mapping (the resolver reuses the Eloquent
 * integration's cast recovery via native reflection): every native cast kind, an enum cast, a custom
 * caster, a no-cast column and a dotted relation path — each proven against a real model's `$casts`.
 */
it('maps a subject-model column cast to its filter-column shape', function (string $column, string $kind, ?array $scalarSchema): void {
    $resolved = (new FilterColumnResolver)->resolve(FilterCastModel::class, $column);

    expect($resolved->kind)->toBe($kind);

    if ($kind === FilterColumn::KIND_SCALAR) {
        expect($resolved->scalarSchema)->toBe($scalarSchema);
    }

    if ($kind === FilterColumn::KIND_ENUM) {
        expect($resolved->enum)->toBe(WidgetStatus::class)
            ->and($resolved->dependencyFiles)->not->toBe([]);
    }
})->with([
    'enum cast' => ['status', FilterColumn::KIND_ENUM, null],
    'boolean cast' => ['active', FilterColumn::KIND_SCALAR, ['type' => 'boolean']],
    'integer cast' => ['quantity', FilterColumn::KIND_SCALAR, ['type' => 'integer']],
    'float cast' => ['rating', FilterColumn::KIND_SCALAR, ['type' => 'number']],
    'datetime cast' => ['published_at', FilterColumn::KIND_SCALAR, ['type' => 'string', 'format' => 'date-time']],
    'immutable_date cast' => ['archived_on', FilterColumn::KIND_SCALAR, ['type' => 'string', 'format' => 'date']],
    'decimal cast' => ['price', FilterColumn::KIND_SCALAR, ['type' => 'string']],
    'string cast' => ['nickname', FilterColumn::KIND_SCALAR, ['type' => 'string']],
    'custom caster' => ['custom', FilterColumn::KIND_NONE, null],
    'no cast' => ['untyped_column', FilterColumn::KIND_NONE, null],
    'dotted relation path' => ['author.name', FilterColumn::KIND_NONE, null],
]);

it('degrades to none for a non-model subject', function (): void {
    expect((new FilterColumnResolver)->resolve('Not\\A\\Model', 'status')->kind)
        ->toBe(FilterColumn::KIND_NONE);
});

/**
 * A filter on the model's primary key types off the key schema, mirroring the path-parameter
 * precedence: a HasUuids/HasUlids format outranks a cast, `$keyType` decides the rest, and an
 * unrecognised custom caster on the key still falls back to the declared key type.
 */
it('types a primary-key filter from the model\'s key schema', function (string $model, array $scalarSchema): void {
    $resolved = (new FilterColumnResolver)->resolve($model, 'id');

    expect($resolved->kind)->toBe(FilterColumn::KIND_SCALAR)
        ->and($resolved->scalarSchema)->toBe($scalarSchema);
})->with([
    'HasUuids key' => [Vault::class, ['type' => 'string', 'format' => 'uuid']],
    'HasUlids key' => [Waybill::class, ['type' => 'string', 'format' => 'ulid']],
    'default int key' => [FilterCastModel::class, ['type' => 'integer']],
    'string keyType' => [Passcard::class, ['type' => 'string']],
    'uuid format beats a stale string cast' => [Locker::class, ['type' => 'string', 'format' => 'uuid']],
    'custom caster on the key falls back to the key type' => [Turnstile::class, ['type' => 'integer']],
]);
