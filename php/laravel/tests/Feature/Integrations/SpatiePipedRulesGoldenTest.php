<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\PipedRuleController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\PipedRuleData;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Rule strings a Spatie Data class pipes together, locked in emitted bytes. No golden held a `#[Rule]` or
 * `rules()` string holding more than one rule.
 */
function spatiePipedRulesBuild(): GenerationResult
{
    bootLaravelData(null);

    $text = UnionT::of([ScalarT::string(), new ClassT(DataClassReflector::OPTIONAL)]);
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            PipedRuleData::class => new ClassMetadata(PipedRuleData::class, [
                new PropertyMetadata('piped', $text),
                new PropertyMetadata('listed', $text),
                new PropertyMetadata('pattern', $text),
                new PropertyMetadata('overridden', $text),
                new PropertyMetadata('overriddenPattern', $text),
            ]),
        ],
        analysisOverrides: [
            PipedRuleController::class.'::store' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))],
            ),
        ],
        traceOverrides: [
            PipedRuleData::class.'::rules' => TraceScript::forMethod(
                (string) (new ReflectionClass(PipedRuleData::class))->getFileName(),
                PipedRuleData::class,
                'rules',
            ),
        ],
    );

    return localityBuild(
        static fn (Router $router) => $router->post('api/zz-piped-rules', [PipedRuleController::class, 'store']),
        $engine,
    );
}

it('emits rule strings a Spatie Data class pipes together byte-identical to their committed golden', function (): void {
    assertGolden('spatie-piped-rules.uir.json', (new UirEmitter)->emit(spatiePipedRulesBuild()->document));
});
