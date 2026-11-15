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
use Docuccino\Laravel\Tests\Fixtures\ConditionalAcceptance\ConsentController;
use Docuccino\Laravel\Tests\Fixtures\ConditionalAcceptance\ConsentData;
use Docuccino\Laravel\Tests\Fixtures\ConditionalAcceptance\StoreConsentRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Fields that must be accepted or declined while another field holds a value — written as the rule strings
 * and as laravel-data's validation attributes — beside the unconditional forms, locked in emitted bytes. No
 * golden held a conditional acceptance.
 */
function conditionalAcceptanceBuild(): GenerationResult
{
    bootLaravelData(null);

    $returns = new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))]);
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            ConsentData::class => new ClassMetadata(ConsentData::class, [
                new PropertyMetadata('kind', ScalarT::string()),
                new PropertyMetadata('terms', ScalarT::bool()),
                new PropertyMetadata('marketing', UnionT::of([ScalarT::bool(), new ClassT(DataClassReflector::OPTIONAL)])),
            ]),
        ],
        analysisOverrides: [
            ConsentController::class.'::store' => $returns,
            ConsentController::class.'::storeData' => $returns,
        ],
        traceOverrides: [StoreConsentRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreConsentRequest::class))->getFileName(),
            StoreConsentRequest::class,
            'rules',
        )],
    );

    return localityBuild(static function (Router $router): void {
        $router->post('api/zz-consents', [ConsentController::class, 'store']);
        $router->post('api/zz-consent-data', [ConsentController::class, 'storeData']);
    }, $engine);
}

it('emits conditionally accepted and declined fields byte-identical to their committed golden', function (): void {
    assertGolden('conditional-acceptance.uir.json', (new UirEmitter)->emit(conditionalAcceptanceBuild()->document));
});
