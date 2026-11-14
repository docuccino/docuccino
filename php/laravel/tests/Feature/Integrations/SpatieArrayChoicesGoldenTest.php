<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArrayChoicesController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArrayChoicesData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MergedArrayChoicesData;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Choice, size and uniqueness attributes on a Spatie Data class's array-typed properties, locked in emitted
 * bytes. No golden held a Data property typed as an array beside a choice rule.
 */
function spatieArrayChoicesBuild(): GenerationResult
{
    bootLaravelData(null);

    $optional = new ClassT(DataClassReflector::OPTIONAL);
    $list = UnionT::of([new ListT(ScalarT::string()), $optional]);
    $map = UnionT::of([new MapT(ScalarT::string(), ScalarT::string()), $optional]);
    $trace = static fn (string $class): callable => TraceScript::forMethod((string) (new ReflectionClass($class))->getFileName(), $class, 'rules');
    $returns = new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))]);

    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            ArrayChoicesData::class => new ClassMetadata(ArrayChoicesData::class, [
                new PropertyMetadata('tags', $list),
                new PropertyMetadata('blocked', $list),
                new PropertyMetadata('labels', $map),
                new PropertyMetadata('banned', $map),
                new PropertyMetadata('maybe', UnionT::of([new ListT(ScalarT::string()), new NullT])),
                new PropertyMetadata('statuses', $list),
                new PropertyMetadata('ruled', $list),
                new PropertyMetadata('few', $list),
                new PropertyMetadata('capped', $map),
                new PropertyMetadata('unique', $list),
                new PropertyMetadata('overridden', $list),
            ]),
            MergedArrayChoicesData::class => new ClassMetadata(MergedArrayChoicesData::class, [
                new PropertyMetadata('merged', $list),
            ]),
        ],
        analysisOverrides: [
            ArrayChoicesController::class.'::store' => $returns,
            ArrayChoicesController::class.'::merge' => $returns,
        ],
        traceOverrides: [
            ArrayChoicesData::class.'::rules' => $trace(ArrayChoicesData::class),
            MergedArrayChoicesData::class.'::rules' => $trace(MergedArrayChoicesData::class),
        ],
    );

    return localityBuild(
        static function (Router $router): void {
            $router->post('api/zz-array-choices', [ArrayChoicesController::class, 'store']);
            $router->post('api/zz-array-choices/merged', [ArrayChoicesController::class, 'merge']);
        },
        $engine,
    );
}

it('emits choice attributes on Spatie Data array properties byte-identical to their committed golden', function (): void {
    assertGolden('spatie-array-choices.uir.json', (new UirEmitter)->emit(spatieArrayChoicesBuild()->document));
});
