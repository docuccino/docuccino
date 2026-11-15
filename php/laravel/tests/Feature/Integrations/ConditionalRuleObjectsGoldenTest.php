<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\ConditionalRuleObjects\ApplicationController;
use Docuccino\Laravel\Tests\Fixtures\ConditionalRuleObjects\StoreApplicationRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Validation\Rules\AcceptedIf;

/*
 * Rules that hold on another field — accepted or declined on a value, missing or present on a value, required
 * with another field or once one is accepted, prohibiting another — written as the rule objects Laravel 13
 * builds them as, beside its timezone, url and uuid objects, locked in emitted bytes. No golden held one.
 */
function conditionalRuleObjectsBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-applications', [ApplicationController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [ApplicationController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreApplicationRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreApplicationRequest::class))->getFileName(),
            StoreApplicationRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits rule objects that hold on another field byte-identical to their committed golden', function (): void {
    assertGolden('conditional-rule-objects.uir.json', (new UirEmitter)->emit(conditionalRuleObjectsBuild()->document));
})->skip(! class_exists(AcceptedIf::class), 'This Laravel builds these rules as strings, not objects.');
