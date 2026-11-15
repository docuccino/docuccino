<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\RuleBuilders\OrderController;
use Docuccino\Laravel\Tests\Fixtures\RuleBuilders\StoreOrderRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/*
 * Rules built with the framework's fluent string, date, number and list builders — `Rule::string()->min(2)` and
 * its siblings, and the objects written with `new` — locked in emitted bytes. No golden held one.
 */
function ruleBuildersBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-orders', [OrderController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [OrderController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreOrderRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreOrderRequest::class))->getFileName(),
            StoreOrderRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits rules built with the framework builders byte-identical to their committed golden', function (): void {
    assertGolden('rule-builders.uir.json', (new UirEmitter)->emit(ruleBuildersBuild()->document));
});
