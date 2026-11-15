<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\ResponseChainController;

/**
 * The document an application gets for a response a fluent chain changes after it is built — its content,
 * data, JSONP callback, encoding or a cookie set, or a setter nothing documents called — for a chain on an
 * application's response subclass, one of which overrides the status setter, and for a `new JsonResponse`
 * built into a local, returned as it is or after its data is set. Byte-locked, warm as well as cold; the
 * scripted returns are what the real engine recovers from the fixture app's same calls, which the last test
 * holds them to.
 *
 * @return array<string, DType> action → the response it returns
 */
function responseChainReturns(): array
{
    $json = 'Illuminate\\Http\\JsonResponse';
    $responses = 'Workbench\\App\\Http\\Responses\\';
    $queued = new ArrayShapeT([new ArrayShapeField('queued', new LiteralT(true))]);
    $sent = static fn (DType $body, int $status): ClassT => new ClassT($json, [$body, new LiteralT($status)]);

    return [
        'note' => new ClassT('Illuminate\\Http\\Response'),
        'accept' => $sent($queued, 202),
        'settle' => $sent($queued, 200),
        'padded' => $sent($queued, 202),
        'batch' => $sent(new ArrayShapeT([new ArrayShapeField('ids', new ArrayShapeT([], isList: true))]), 202),
        'pretty' => $sent($queued, 202),
        'remember' => $sent($queued, 202),
        'unchanged' => $sent($queued, 202),
        'removed' => new ClassT($responses.'GoneJsonResponse'),
        'relocated' => new ClassT($responses.'MovedResponse'),
        'locked' => new ClassT($responses.'PinnedStatusJsonResponse'),
        'built' => new ClassT($json, [new UnknownT('mixed'), ScalarT::int(), ScalarT::string()]),
        'refilled' => new ClassT($json, [new UnknownT('payload not folded'), new UnknownT('status not folded')]),
    ];
}

/** @return callable(): TypeEngine */
function responseChainEngine(): callable
{
    $analyses = [];
    foreach (responseChainReturns() as $action => $type) {
        $analyses[ResponseChainController::class.'::'.$action] = new ActionAnalysis(returns: [new ReturnSite($type, new SourceLocation(''))]);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function responseChainRoutes(): callable
{
    return static function (Router $router): void {
        $router->post('api/chains/note', [ResponseChainController::class, 'note']);
        $router->post('api/chains/accept', [ResponseChainController::class, 'accept']);
        $router->post('api/chains/settle', [ResponseChainController::class, 'settle']);
        $router->post('api/chains/padded', [ResponseChainController::class, 'padded']);
        $router->post('api/chains/batch', [ResponseChainController::class, 'batch']);
        $router->post('api/chains/pretty', [ResponseChainController::class, 'pretty']);
        $router->post('api/chains/remember', [ResponseChainController::class, 'remember']);
        $router->get('api/chains/unchanged', [ResponseChainController::class, 'unchanged']);
        $router->get('api/chains/removed', [ResponseChainController::class, 'removed']);
        $router->get('api/chains/relocated', [ResponseChainController::class, 'relocated']);
        $router->get('api/chains/locked', [ResponseChainController::class, 'locked']);
        $router->post('api/chains/built', [ResponseChainController::class, 'built']);
        $router->post('api/chains/refilled', [ResponseChainController::class, 'refilled']);
    };
}

it('publishes the response a fluent chain leaves, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(responseChainRoutes(), responseChainRoutes(), responseChainEngine());

    assertGolden('workbench-response-chain.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (string $action, string $fixtureMethod): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/ResponseChainController.php',
        'App\\Http\\Controllers\\ResponseChainController',
        $fixtureMethod,
    ));

    // The fixture app's response classes are the workbench's under another namespace; JSON escapes the
    // separators it is named with.
    $named = static fn (DType $type): string => str_replace('Workbench\\\\App\\\\', 'App\\\\', json_encode($type->toArray(), JSON_THROW_ON_ERROR));

    expect(array_map(static fn (ReturnSite $site): string => $named($site->type), $real->returns))
        ->toBe([$named(responseChainReturns()[$action])]);
})->with([
    'response($text, 202)->setContent($text)' => ['note', 'content'],
    'response()->json(…, 202)->setData(…)' => ['accept', 'data'],
    'response()->json(…)->setData(…)->setStatusCode(202)' => ['settle', 'dataThenStatus'],
    'setCallback($request->query(…))' => ['padded', 'callback'],
    'setEncodingOptions(JSON_FORCE_OBJECT)' => ['batch', 'forceObject'],
    'setEncodingOptions(JSON_PRETTY_PRINT)' => ['pretty', 'pretty'],
    'cookie(…)' => ['remember', 'cookie'],
    'setNotModified()' => ['unchanged', 'unknownLink'],
    '(new GoneJsonResponse)->setStatusCode(404)' => ['removed', 'subclass'],
    '(new MovedResponse($url))->setStatusCode(308)' => ['relocated', 'subclassRedirect'],
    'setStatusCode(404) on a subclass overriding it' => ['locked', 'overridden'],
    '$response = new JsonResponse(…, 202); return $response;' => ['built', 'localJson'],
    'new JsonResponse(…, 202) in a local, then setData(…)' => ['refilled', 'localData'],
])->group('fixture');
