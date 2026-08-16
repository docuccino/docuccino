<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Support\PaginatorPageParameter;

/**
 * The one place a Laravel page selector is minted, pinned per kind — including the kind it has never
 * heard of, which degrades to the length-aware key exactly as the envelope builder does.
 */
it('mints the key each paginator kind reads', function (?string $kind, string $name, array $schema, string $description): void {
    $spec = PaginatorPageParameter::for($kind);

    expect($spec->name)->toBe($name)
        ->and($spec->schema)->toBe($schema)
        ->and($spec->description)->toBe($description)
        ->and($spec->style)->toBeNull()
        ->and($spec->explode)->toBeNull()
        ->and($spec->example)->toBeNull();
})->with([
    'length' => ['length', 'page', ['type' => 'integer', 'default' => 1, 'minimum' => 1], 'Page number.'],
    'simple' => ['simple', 'page', ['type' => 'integer', 'default' => 1, 'minimum' => 1], 'Page number.'],
    'cursor' => ['cursor', 'cursor', ['type' => 'string'], 'Opaque cursor for the next/previous page.'],
    'an unknown kind' => ['weekly', 'page', ['type' => 'integer', 'default' => 1, 'minimum' => 1], 'Page number.'],
    'no kind at all' => [null, 'page', ['type' => 'integer', 'default' => 1, 'minimum' => 1], 'Page number.'],
]);

it('carries the name the call site chose instead of the default', function (?string $kind, string $name): void {
    expect(PaginatorPageParameter::for($kind, $name)->name)->toBe($name);
})->with([
    'a renamed page' => ['length', 'p'],
    'a renamed cursor' => ['cursor', 'after'],
]);
