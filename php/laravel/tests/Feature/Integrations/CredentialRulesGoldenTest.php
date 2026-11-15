<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\CredentialRules\AccountController;
use Docuccino\Laravel\Tests\Fixtures\CredentialRules\StoreAccountRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/*
 * Credentials checked by the framework's password and email builders, as `new`, a static of the rule's own
 * class and a `Rule::` factory, locked in emitted bytes. No golden held one.
 */
function credentialRulesBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-accounts', [AccountController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [AccountController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreAccountRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreAccountRequest::class))->getFileName(),
            StoreAccountRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits credentials checked by the framework builders byte-identical to their committed golden', function (): void {
    assertGolden('credential-rules.uir.json', (new UirEmitter)->emit(credentialRulesBuild()->document));
});
