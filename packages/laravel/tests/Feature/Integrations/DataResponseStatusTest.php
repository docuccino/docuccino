<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataResponseStatus;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AccountData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\CreatedData;

/**
 * calculateResponseStatus() folding (gap 5): a Data class overriding the method to a single constant
 * status re-homes the inferred 200; a non-override is a no-op; a non-constant/multi-status override
 * degrades to 200 with a diagnostic. The override DETECTION is real reflection; the folded return
 * TYPE is scripted by the stub (the engine's literal-int inference is proven independently).
 */
function statusContext(StubTypeEngine $engine): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/things'),
        actionRef: new ActionRef('', null, 'store'),
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', []),
    );
}

/** @param list<DType> $returnTypes */
function statusEngine(string $fqcn, array $returnTypes): StubTypeEngine
{
    $loc = new SourceLocation('');

    return new StubTypeEngine(analyses: [
        $fqcn.'::calculateResponseStatus' => new ActionAnalysis(
            returns: array_map(static fn ($t): ReturnSite => new ReturnSite($t, $loc), $returnTypes),
        ),
    ]);
}

it('folds a single constant status override to the response status', function (): void {
    $context = statusContext(statusEngine(CreatedData::class, [new LiteralT(201)]));

    expect((new DataResponseStatus)->resolveStatus($context, CreatedData::class))->toBe(201)
        ->and($context->components->diagnostics())->toBe([]);
});

it('leaves a plain Data class (no override) at the inferred status with no diagnostic', function (): void {
    // AccountData does not override calculateResponseStatus — the trait default reports the vendor
    // file, so it is not treated as a documentable override.
    $context = statusContext(new StubTypeEngine);

    expect((new DataResponseStatus)->resolveStatus($context, AccountData::class))->toBeNull()
        ->and($context->components->diagnostics())->toBe([]);
});

it('degrades a non-constant or multi-status override to 200 with a diagnostic', function (array $returnTypes): void {
    $context = statusContext(statusEngine(CreatedData::class, $returnTypes));

    expect((new DataResponseStatus)->resolveStatus($context, CreatedData::class))->toBeNull();

    $diagnostics = $context->components->diagnostics();
    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('spatie-data.response-status-unresolved');
})->with([
    'two distinct constant statuses' => [[new LiteralT(201), new LiteralT(202)]],
    'a widened (non-literal) int status' => [[ScalarT::int()]],
]);
