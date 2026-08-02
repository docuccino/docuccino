<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Ports Spike A's return-path pass criteria: per-return flow-refined types, the
 * JsonResponse payload-shape stub, resource collections, and a distinct type per
 * return in a union action. Assertions run over the serialized ActionAnalysis
 * the engine subprocess emits.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * @return list<array<string, mixed>>
 */
function spikeReturns(string $method): array
{
    $analysis = FixtureRunner::analyze(
        'app/Http/Controllers/SpikeController.php',
        'App\\Http\\Controllers\\SpikeController',
        $method,
    );

    /** @var list<array<string, mixed>> $returns */
    $returns = $analysis['returns'];

    return $returns;
}

it('recovers an Eloquent Collection generic for listUsers', function (): void {
    $returns = spikeReturns('listUsers');

    expect($returns)->toHaveCount(1);
    $type = $returns[0]['type'];
    expect($type['kind'])->toBe('class')
        ->and($type['fqcn'])->toContain('Collection')
        ->and($type['typeArgs'])->not->toBeEmpty();
    $last = $type['typeArgs'][count($type['typeArgs']) - 1];
    expect($last['kind'])->toBe('class')
        ->and($last['fqcn'])->toBe('App\\Models\\User');
})->group('fixture');

it('recovers the JsonResponse payload shape and folded status via the bundled stub', function (): void {
    $returns = spikeReturns('jsonShape');

    expect($returns)->toHaveCount(1);
    $type = $returns[0]['type'];
    // JsonResponse<arrayShape{...}, 200>: the payload shape plus the default folded status literal.
    expect($type['kind'])->toBe('class')
        ->and($type['fqcn'])->toBe('Illuminate\\Http\\JsonResponse')
        ->and($type['typeArgs'])->toHaveCount(2)
        ->and($type['typeArgs'][0]['kind'])->toBe('arrayShape')
        ->and($type['typeArgs'][1]['kind'])->toBe('literal')
        ->and($type['typeArgs'][1]['value'])->toBe(200);
})->group('fixture');

it('recovers an AnonymousResourceCollection for resourceCollection', function (): void {
    $returns = spikeReturns('resourceCollection');

    expect($returns)->toHaveCount(1);
    expect($returns[0]['type']['fqcn'])->toContain('AnonymousResourceCollection');
})->group('fixture');

it('reports a distinct type per return path in a union action', function (): void {
    $returns = spikeReturns('unionAction');

    expect($returns)->toHaveCount(2);
    $fqcns = array_map(static fn (array $r): string => $r['type']['fqcn'] ?? '?', $returns);
    expect($fqcns)->toContain('Illuminate\\Http\\JsonResponse')
        ->and($fqcns)->toContain('App\\Http\\Resources\\UserResource');

    expect($returns[0]['location']['line'] ?? null)->not->toBe($returns[1]['location']['line'] ?? null);
})->group('fixture');
