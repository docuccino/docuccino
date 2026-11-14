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
use Docuccino\Laravel\Tests\Fixtures\FormatRules\DeviceController;
use Docuccino\Laravel\Tests\Fixtures\FormatRules\StoreDeviceRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/*
 * String rules Laravel checks against a fixed grammar, locked in emitted bytes. No golden held one.
 */
function formatRulesBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-devices', [DeviceController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [DeviceController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreDeviceRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreDeviceRequest::class))->getFileName(),
            StoreDeviceRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits rules checked against a fixed grammar byte-identical to their committed goldens', function (): void {
    $result = formatRulesBuild();

    assertGolden('format-rules.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('format-rules.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
});
