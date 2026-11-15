<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\CarriedResponseController;

/**
 * The document an application gets for a response it hands the framework inside an exception: an
 * `HttpResponseException` carrying a response built each way one is, thrown at the action and from a helper,
 * and a response handed to `abort()`, which throws one. Byte-locked, warm as well as cold; the scripted
 * analyses are what the real engine recovers from the fixture app's same calls, which the last test holds
 * them to.
 *
 * @return array<string, ActionAnalysis> action → what the engine recovers from it
 */
function carriedResponseAnalyses(): array
{
    $location = new SourceLocation('');
    $carried = static fn (): ThrownException => new ThrownException(
        'Illuminate\\Http\\Exceptions\\HttpResponseException',
        500,
        [],
        ThrowConfidence::Certain,
        ThrowDisposition::Signal,
    );
    $aborted = static fn (): ThrownException => new ThrownException(
        'Symfony\\Component\\HttpKernel\\Exception\\HttpException',
        null,
        [],
        ThrowConfidence::Certain,
        ThrowDisposition::Signal,
    );
    $ok = new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([new ArrayShapeField('ok', new LiteralT(true))]),
        new LiteralT(200),
    ]), $location);

    return [
        'helperJson' => new ActionAnalysis(throws: [$carried()]),
        'facadeJson' => new ActionAnalysis(throws: [$carried()]),
        'newJson' => new ActionAnalysis(throws: [$carried()]),
        'text' => new ActionAnalysis(throws: [$carried()]),
        'redirect' => new ActionAnalysis(throws: [$carried()]),
        'subclass' => new ActionAnalysis(throws: [$carried()]),
        'viaHelper' => new ActionAnalysis(returns: [$ok], throws: [$carried()]),
        'caseHelper' => new ActionAnalysis(throws: [$carried()]),
        'caseChosen' => new ActionAnalysis(throws: [$carried()]),
        'abortJson' => new ActionAnalysis(throws: [$aborted()]),
        'abortFacade' => new ActionAnalysis(throws: [$aborted()]),
        'abortIf' => new ActionAnalysis(returns: [$ok], throws: [$aborted()]),
    ];
}

/** @return callable(): TypeEngine */
function carriedResponseEngine(): callable
{
    $analyses = [];
    foreach (carriedResponseAnalyses() as $action => $analysis) {
        $analyses[CarriedResponseController::class.'::'.$action] = $analysis;
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function carriedResponseRoutes(): callable
{
    return static function (Router $router): void {
        foreach (array_keys(carriedResponseAnalyses()) as $action) {
            $router->post('api/carried/'.$action, [CarriedResponseController::class, $action]);
        }
    };
}

it('publishes the response an exception carries, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(carriedResponseRoutes(), carriedResponseRoutes(), carriedResponseEngine());

    assertGolden('workbench-carried-response.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $actions = array_keys(carriedResponseAnalyses());
    $real = FixtureRunner::analyzeMany(
        'app/Http/Controllers/CarriedResponseController.php',
        'App\\Http\\Controllers\\CarriedResponseController',
        $actions,
    );

    // What reaches the document: every return's type, and each throw it publishes.
    $facts = static fn (ActionAnalysis $analysis): array => [
        array_map(static fn (ReturnSite $site): array => $site->type->toArray(), $analysis->returns),
        array_values(array_map(
            static fn (ThrownException $throw): string => $throw->identityKey(),
            array_filter($analysis->throws, static fn (ThrownException $throw): bool => $throw->disposition === ThrowDisposition::Signal),
        )),
    ];

    foreach (carriedResponseAnalyses() as $action => $scripted) {
        /** @var array<string, mixed> $recovered */
        $recovered = $real[$action];

        expect($facts(ActionAnalysis::fromArray($recovered)))->toBe($facts($scripted), $action);
    }
})->group('fixture');
