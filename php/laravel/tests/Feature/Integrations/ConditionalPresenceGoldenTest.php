<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\ConditionalPresence\ProfileController;
use Docuccino\Laravel\Tests\Fixtures\ConditionalPresence\StoreProfileRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/*
 * Required and prohibited rules that hold on a condition — a literal, a closure, a value only the request
 * decides — written as `new` objects, as `Rule::` factories and as the cross-field strings, locked in
 * emitted bytes. No golden held a conditional rule object.
 */
function conditionalPresenceBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-profiles', [ProfileController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [ProfileController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreProfileRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreProfileRequest::class))->getFileName(),
            StoreProfileRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits conditional required and prohibited rules byte-identical to their committed golden', function (): void {
    assertGolden('conditional-presence.uir.json', (new UirEmitter)->emit(conditionalPresenceBuild()->document));
});
