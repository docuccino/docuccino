<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AttributeKeywordController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AttributeKeywordData;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Validation attributes whose rule is not their class name snake-cased, and one whose arguments are named out of
 * order, locked in emitted bytes. No golden held one.
 */
function spatieAttributeKeywordsBuild(): GenerationResult
{
    bootLaravelData(null);

    $optional = new ClassT(DataClassReflector::OPTIONAL);
    $of = static fn ($type): UnionT => UnionT::of([$type, $optional]);
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            AttributeKeywordData::class => new ClassMetadata(AttributeKeywordData::class, [
                new PropertyMetadata('items', $of(new UnknownT('untyped array'))),
                new PropertyMetadata('above', $of(ScalarT::int())),
                new PropertyMetadata('atLeast', $of(ScalarT::int())),
                new PropertyMetadata('below', $of(ScalarT::int())),
                new PropertyMetadata('atMost', $of(ScalarT::int())),
                new PropertyMetadata('v4', $of(ScalarT::string())),
                new PropertyMetadata('v6', $of(ScalarT::string())),
                new PropertyMetadata('count', $of(ScalarT::int())),
                new PropertyMetadata('avatar', $of(new ClassT('Illuminate\\Http\\UploadedFile'))),
            ]),
        ],
        analysisOverrides: [
            AttributeKeywordController::class.'::store' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))],
            ),
        ],
    );

    return localityBuild(
        static fn (Router $router) => $router->post('api/zz-attribute-keywords', [AttributeKeywordController::class, 'store']),
        $engine,
    );
}

it('emits validation attributes named apart from their rules byte-identical to their committed golden', function (): void {
    assertGolden('spatie-attribute-keywords.uir.json', (new UirEmitter)->emit(spatieAttributeKeywordsBuild()->document));
});
