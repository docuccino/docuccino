<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\ArrayChoices\ChoiceListController;
use Docuccino\Laravel\Tests\Fixtures\ArrayChoices\StoreChoiceListRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/*
 * Choice lists — `in`, `Rule::in`, `not_in`, `Rule::notIn` — beside an `array` rule, locked in emitted bytes.
 * Nothing else in the golden corpus publishes a choice list on a field that is an array.
 */
function arrayChoicesBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-choice-lists', [ChoiceListController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [ChoiceListController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreChoiceListRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreChoiceListRequest::class))->getFileName(),
            StoreChoiceListRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits choice lists beside an array rule byte-identical to their committed goldens', function (): void {
    $result = arrayChoicesBuild();

    assertGolden('array-choices.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('array-choices.openapi31.json', (new OpenApi31DownlevelEmitter)->emit($result->document));
    assertGolden('array-choices.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
});
