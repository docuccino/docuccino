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
use Docuccino\Laravel\Tests\Fixtures\ArrayItemRules\IssueTokenRequest;
use Docuccino\Laravel\Tests\Fixtures\ArrayItemRules\TokenController;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/*
 * Lists validated item by item through `field.*` keys, locked in emitted bytes: closed sets written as
 * rule objects and as `Rule::` factories, and `distinct` at every depth a `*` can put it. Nothing else in
 * the golden corpus publishes a `distinct` rule or a rule object's value set.
 */
function arrayItemRulesBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-tokens', [TokenController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [TokenController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [IssueTokenRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(IssueTokenRequest::class))->getFileName(),
            IssueTokenRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits item-level rules byte-identical to their committed goldens', function (): void {
    $result = arrayItemRulesBuild();

    assertGolden('array-item-rules.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('array-item-rules.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
});
