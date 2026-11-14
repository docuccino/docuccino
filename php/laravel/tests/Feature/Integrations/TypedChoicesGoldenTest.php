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
use Docuccino\Laravel\Tests\Fixtures\TypedChoices\ChoiceController;
use Docuccino\Laravel\Tests\Fixtures\TypedChoices\StoreChoiceRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/*
 * Choice lists — `in`, `Rule::in`, `Rule::enum`, `Rule::notIn` — beside a type rule that types the field,
 * locked in emitted bytes. Nothing else in the golden corpus publishes a list whose values read as another
 * type than the one the field declares.
 */
function typedChoicesBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-choices', [ChoiceController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [ChoiceController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreChoiceRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreChoiceRequest::class))->getFileName(),
            StoreChoiceRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits typed choice lists byte-identical to their committed goldens', function (): void {
    $result = typedChoicesBuild();

    assertGolden('typed-choices.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('typed-choices.openapi31.json', (new OpenApi31DownlevelEmitter)->emit($result->document));
    assertGolden('typed-choices.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
});
