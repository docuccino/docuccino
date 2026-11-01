<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\EnumCaseRules\PostController;
use Docuccino\Laravel\Tests\Fixtures\EnumCaseRules\StorePostRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/*
 * Choice rules naming enum cases, locked in emitted bytes: `Rule::in`, `new In`, `Rule::notIn`, `new NotIn`
 * and an enum rule's `->only()`/`->except()`, over string-backed, int-backed and pure enums. Nothing else in
 * the golden corpus lists a case where a value goes.
 */
function enumCaseRulesBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-posts', [PostController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [PostController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StorePostRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StorePostRequest::class))->getFileName(),
            StorePostRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits choice rules naming enum cases byte-identical to their committed goldens', function (): void {
    $result = enumCaseRulesBuild();

    assertGolden('enum-case-rules.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('enum-case-rules.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
});
