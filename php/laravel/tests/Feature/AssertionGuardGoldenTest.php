<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Exceptions\UnsupportedRadiusException;
use Workbench\App\Http\Controllers\AssertionGuardController;

/**
 * The document an application gets for throws with no status that assert what it already guarantees,
 * beside the same throws behind a test of a value and exceptions the framework or the application renders
 * at a status. An assertion is no response a request receives, so it is published only at a status the
 * application renders it with. Byte-locked, warm as well as cold; the scripted analyses are what the real
 * engine recovers from the fixture app's same actions, which a test below holds them to.
 *
 * @return array<string, ActionAnalysis>
 */
function assertionGuardAnalyses(): array
{
    $ok = new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([new ArrayShapeField('ok', new LiteralT(true))]),
        new LiteralT(200),
    ]), new SourceLocation(''));
    $thrown = static fn (string $class, ThrowDisposition $disposition): ThrownException => new ThrownException($class, 500, [], ThrowConfidence::Certain, $disposition);
    $asserted = static fn (string $class): ThrownException => $thrown($class, ThrowDisposition::Assertion);
    $signal = static fn (string $class): ThrownException => $thrown($class, ThrowDisposition::Signal);

    return [
        'listWithNeverHelper' => new ActionAnalysis(returns: [$ok], throws: [$asserted('LogicException')]),
        'unlessUser' => new ActionAnalysis(returns: [$ok], throws: [$asserted('RuntimeException')]),
        'unlessUserAuthenticated' => new ActionAnalysis(returns: [$ok], throws: [$asserted('Illuminate\\Auth\\AuthenticationException')]),
        'ifSystemAuthenticated' => new ActionAnalysis(returns: [$ok], throws: [$signal('Illuminate\\Auth\\AuthenticationException')]),
        'radiusOutOfRange' => new ActionAnalysis(returns: [$ok], throws: [$asserted(UnsupportedRadiusException::class)]),
        'valueGuard' => new ActionAnalysis(returns: [$ok], throws: [$signal('RuntimeException')]),
        'sameClassTwoSites' => new ActionAnalysis(returns: [$ok], throws: [$signal('RuntimeException')]),
        'ignoredAssertion' => new ActionAnalysis(returns: [$ok], throws: [$asserted('LogicException')]),
    ];
}

/** @return callable(): TypeEngine */
function assertionGuardEngine(): callable
{
    $analyses = [];
    foreach (assertionGuardAnalyses() as $action => $analysis) {
        $analyses[AssertionGuardController::class.'::'.$action] = $analysis;
    }

    // The application's render() as the engine recovers it.
    $render = new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([new ArrayShapeField('message', ScalarT::string())]),
        new LiteralT(422),
    ]), new SourceLocation(''))]);

    return static fn (): TypeEngine => WorkbenchEngine::make(
        callables: [UnsupportedRadiusException::class.'::render' => $render],
        analysisOverrides: $analyses,
    );
}

function assertionGuardRoutes(): callable
{
    return static function (Router $router): void {
        foreach (array_keys(assertionGuardAnalyses()) as $action) {
            $router->post('api/asserted/'.$action, [AssertionGuardController::class, $action]);
        }
    };
}

it('publishes no 500 for an assertion, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(assertionGuardRoutes(), assertionGuardRoutes(), assertionGuardEngine());

    assertGolden('workbench-assertion-guard.uir.json', (new UirEmitter)->emit($warm->document));
});
