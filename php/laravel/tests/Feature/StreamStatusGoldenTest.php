<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\ExportStreamController;

/**
 * The document an application gets for streams, files and redirects sent with a status of their own —
 * `response()->stream($cb, 202)`, `streamJson($rows, 206)`, a choice of two, `new BinaryFileResponse($f, 206)`,
 * `redirect()->to($url, 301)`, `back(303)` — beside a stream sent with the framework's 200, a redirect with
 * its 302, and one of each whose status nothing can read. Byte-locked, warm as well as cold; the scripted
 * returns are what the real engine recovers from the fixture app's same calls, which the last test holds
 * them to.
 *
 * @return array<string, DType> action → the response it returns
 */
function streamStatusReturns(): array
{
    $stream = new ClassT('Symfony\\Component\\HttpFoundation\\StreamedResponse');
    $json = new ClassT('Symfony\\Component\\HttpFoundation\\StreamedJsonResponse');
    $redirect = new ClassT('Illuminate\\Http\\RedirectResponse');

    return [
        'queue' => $stream,
        'rows' => $json,
        'slice' => $json,
        'report' => new ClassT('Symfony\\Component\\HttpFoundation\\BinaryFileResponse'),
        'export' => $stream,
        'relay' => $stream,
        'moved' => $redirect,
        'finished' => $redirect,
        'latest' => $redirect,
        'follow' => $redirect,
    ];
}

/** @return callable(): TypeEngine */
function streamStatusEngine(): callable
{
    $analyses = [];
    foreach (streamStatusReturns() as $action => $type) {
        $analyses[ExportStreamController::class.'::'.$action] = new ActionAnalysis(returns: [new ReturnSite($type, new SourceLocation(''))]);
    }

    // The calls the media type is read off, as the engine's walk hands them to the visitor.
    $factory = 'Illuminate\\Contracts\\Routing\\ResponseFactory';
    $traces = [
        'queue' => "response()->stream(fn () => null, 202, ['Content-Type' => 'text/csv'])",
        'rows' => "response()->streamJson(['rows' => []], 206)",
        'slice' => "response()->streamJson(['rows' => []], \$partial ? 206 : 200)",
        'export' => "response()->stream(fn () => null, 200, ['Content-Type' => 'text/csv'])",
        'relay' => "response()->stream(fn () => null, \$request->integer('status'), ['Content-Type' => 'text/csv'])",
    ];
    $scripted = [];
    foreach ($traces as $action => $call) {
        $scripted[ExportStreamController::class.'::'.$action] = TraceScript::forChain($call, $factory, "ExportStreamController/$action.php");
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses, traceOverrides: $scripted);
}

function streamStatusRoutes(): callable
{
    return static function (Router $router): void {
        $router->post('api/exports/queue', [ExportStreamController::class, 'queue']);
        $router->get('api/exports/rows', [ExportStreamController::class, 'rows']);
        $router->get('api/exports/slice', [ExportStreamController::class, 'slice']);
        $router->get('api/exports/report', [ExportStreamController::class, 'report']);
        $router->get('api/exports/export', [ExportStreamController::class, 'export']);
        $router->get('api/exports/relay', [ExportStreamController::class, 'relay']);
        $router->get('api/exports/moved', [ExportStreamController::class, 'moved']);
        $router->post('api/exports/finished', [ExportStreamController::class, 'finished']);
        $router->get('api/exports/latest', [ExportStreamController::class, 'latest']);
        $router->get('api/exports/follow', [ExportStreamController::class, 'follow']);
    };
}

it('publishes the status a stream, file or redirect is sent with, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(streamStatusRoutes(), streamStatusRoutes(), streamStatusEngine());

    assertGolden('workbench-stream-status.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (string $action, string $fixture, string $fixtureMethod): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = ActionAnalysis::fromArray(FixtureRunner::analyze(
        "app/Http/Controllers/$fixture.php",
        'App\\Http\\Controllers\\'.$fixture,
        $fixtureMethod,
    ));

    expect(array_map(static fn (ReturnSite $site): DType => $site->type, $real->returns))
        ->toEqual([streamStatusReturns()[$action]]);
})->with([
    'response()->stream($cb, 202, $headers)' => ['queue', 'StreamStatusController', 'stream'],
    'response()->streamJson($rows, 206)' => ['rows', 'StreamStatusController', 'streamJson'],
    'response()->streamJson($rows, $partial ? 206 : 200)' => ['slice', 'StreamStatusController', 'choice'],
    'new BinaryFileResponse($file, 206)' => ['report', 'StreamStatusController', 'constructedFile'],
    'response()->stream($cb, 200, $headers), the framework\'s own 200' => ['export', 'StreamStatusController', 'streamOk'],
    'response()->stream($cb, $request->integer(…))' => ['relay', 'StreamStatusController', 'unread'],
    'redirect()->to($url, 301)' => ['moved', 'RedirectStatusController', 'to'],
    'back(303)' => ['finished', 'RedirectStatusController', 'back'],
    'redirect($url), the framework\'s 302' => ['latest', 'RedirectStatusController', 'plain'],
    'redirect()->to($url, $request->integer(…))' => ['follow', 'RedirectStatusController', 'unread'],
])->group('fixture');
