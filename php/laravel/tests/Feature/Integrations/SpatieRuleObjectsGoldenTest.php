<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\RuleObjectChoicesController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\RuleObjectChoicesData;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Spatie\LaravelData\Attributes\Validation\Enum;

/*
 * Framework rule objects handed to Spatie Data's validation attributes, locked in emitted bytes. No golden
 * held a Data property whose rule is an object rather than a string.
 */
function spatieRuleObjectsBuild(): GenerationResult
{
    // spatie/laravel-data's `#[Enum]` takes `only`/`except` from 4.x; before it no application can write one.
    $parameters = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(Enum::class, '__construct'))->getParameters(),
    );
    if (! in_array('only', $parameters, true)) {
        test()->markTestSkipped('The installed spatie/laravel-data takes no `only` in #[Enum].');
    }

    bootLaravelData(null);

    $string = ScalarT::string();
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            RuleObjectChoicesData::class => new ClassMetadata(RuleObjectChoicesData::class, [
                new PropertyMetadata('status', $string),
                new PropertyMetadata('size', $string),
                new PropertyMetadata('priority', ScalarT::int()),
                new PropertyMetadata('code', $string),
                new PropertyMetadata('stage', $string),
                new PropertyMetadata('phase', $string),
                new PropertyMetadata('state', $string),
                new PropertyMetadata('colour', $string),
                new PropertyMetadata('mark', $string),
                new PropertyMetadata('note', UnionT::of([$string, new NullT])),
            ]),
        ],
        analysisOverrides: [
            RuleObjectChoicesController::class.'::store' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))],
            ),
        ],
    );

    return localityBuild(
        static fn (Router $router) => $router->post('api/zz-rule-objects', [RuleObjectChoicesController::class, 'store']),
        $engine,
    );
}

it('emits Spatie attributes handing over rule objects byte-identical to their committed golden', function (): void {
    assertGolden('spatie-rule-objects.uir.json', (new UirEmitter)->emit(spatieRuleObjectsBuild()->document));
});
