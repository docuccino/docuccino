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
use Docuccino\Laravel\Tests\Fixtures\ExclusionRules\AccountController;
use Docuccino\Laravel\Tests\Fixtures\ExclusionRules\StoreAccountRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/*
 * Rules that only exclude values — `not_in`, `different`, `not_regex`, `doesnt_start_with`,
 * `doesnt_end_with`, the prohibitions — locked in emitted bytes, on fields no type rule types and beside
 * ones that do. Nothing else in the golden corpus publishes an exclusion on an untyped field.
 */
function exclusionRulesBuild(): GenerationResult
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

it('emits exclusion-only rules byte-identical to their committed goldens', function (): void {
    $result = exclusionRulesBuild();

    assertGolden('exclusion-rules.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('exclusion-rules.openapi31.json', (new OpenApi31DownlevelEmitter)->emit($result->document));
    assertGolden('exclusion-rules.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
});
