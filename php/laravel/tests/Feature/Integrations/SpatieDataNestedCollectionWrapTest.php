<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapDisabledData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapItemData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapListData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapTransformedData;

/*
 * spatie unwraps a nested single Data object and re-wraps a nested COLLECTION. The document keeps the
 * bare array on purpose, so the divergence is reported instead of modelled — these prove where that
 * report fires, and the last two prove the vendor behaviour the whole diagnostic rests on.
 */

it('says a nested collection will be wrapped where a global wrap is set', function (string $fqcn): void {
    $result = convertNestedWrap($fqcn, 'data');

    expect($result['codes'])->toContain('spatie-data.nested-collection-wrap')
        ->and($result['schema']['properties']['things'])->toBe([
            'type' => 'array',
            'items' => ['$ref' => '#/components/schemas/NestedWrapItemData'],
        ]);

    $diagnostic = $result['diagnostics']['spatie-data.nested-collection-wrap'];

    expect($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->message)->toContain('$things')
        ->and($diagnostic->message)->toContain(NestedWrapItemData::class)
        ->and($diagnostic->message)->toContain('{"data": [ … ]}')
        ->and($diagnostic->help)->toContain('overlay');
})->with([
    'a plain array with a recovered generic' => NestedWrapListData::class,
]);

it('stays silent where nothing will be wrapped', function (string $fqcn, ?string $wrap): void {
    expect(convertNestedWrap($fqcn, $wrap)['codes'])->not->toContain('spatie-data.nested-collection-wrap');
})->with([
    'no global wrap is configured' => [NestedWrapListData::class, null],
    'the property carries a transformer' => [NestedWrapTransformedData::class, 'data'],
    'the class disables wrapping outright' => [NestedWrapDisabledData::class, 'data'],
]);

it('leaves a transformed property its declared shape', function (): void {
    expect(convertNestedWrap(NestedWrapTransformedData::class, 'data')['schema']['properties']['things'])
        ->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/NestedWrapItemData']]);
});

// The oracle. Nothing else in the suite compares what the integration believes about spatie to what
// spatie does, which is how the wrapped nested collection went unnoticed.

it('pins that laravel-data really does wrap a nested collection in a response', function (): void {
    bootLaravelData('data');

    $rendered = (new NestedWrapListData([new NestedWrapItemData('a')]))
        ->toResponse(request())
        ->getData(true);

    expect($rendered)->toBe(['data' => ['things' => ['data' => [['label' => 'a']]]]]);
});

it('pins the two ways a nested collection comes back bare', function (): void {
    bootLaravelData('data');

    $transformed = (new NestedWrapTransformedData([new NestedWrapItemData('a')]))
        ->toResponse(request())
        ->getData(true);

    $disabled = (new NestedWrapDisabledData([new NestedWrapItemData('a')]))
        ->toResponse(request())
        ->getData(true);

    expect($transformed)->toBe(['data' => ['things' => [['label' => 'a']]]])
        ->and($disabled)->toBe(['things' => [['label' => 'a']]]);
});

it('names the global wrap key, which is the one spatie puts a nested collection under', function (): void {
    bootLaravelData('envelope');

    $rendered = (new NestedWrapListData([new NestedWrapItemData('a')]))
        ->toResponse(request())
        ->getData(true);

    expect($rendered['envelope']['things'])->toHaveKey('envelope')
        ->and(convertNestedWrap(NestedWrapListData::class, 'envelope')['diagnostics']['spatie-data.nested-collection-wrap']->message)
        ->toContain('{"envelope": [ … ]}');
});
