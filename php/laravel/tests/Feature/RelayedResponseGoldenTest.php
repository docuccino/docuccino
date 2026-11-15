<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\RelayedResponseController;

/**
 * The document an application gets for a response it throws inside its own subclass of `HttpResponseException`:
 * one handing its parent the response it is given, one building that response itself, from a value it is handed
 * or not, two levels below the framework each way, and one built by its static factories. Byte-locked, warm as well as cold; the scripted
 * analyses are what the real engine recovers from the fixture app's same calls, which the last test holds
 * them to.
 *
 * @return array<string, ActionAnalysis> action → what the engine recovers from it
 */
function relayedResponseAnalyses(): array
{
    $thrown = static fn (string $class): ThrownException => new ThrownException(
        'Workbench\\App\\Exceptions\\Relayed\\'.$class,
        500,
        [],
        ThrowConfidence::Certain,
        ThrowDisposition::Signal,
    );

    return [
        'handedOn' => new ActionAnalysis(throws: [$thrown('RelayedResponseException')]),
        'handedOnNamed' => new ActionAnalysis(throws: [$thrown('RelayedResponseException')]),
        'builtItself' => new ActionAnalysis(throws: [$thrown('ExportLockedResponseException')]),
        'twoLevelsHandedOn' => new ActionAnalysis(throws: [$thrown('UpstreamRelayedException')]),
        'twoLevelsBuilt' => new ActionAnalysis(throws: [$thrown('QuotaExceededException')]),
        'builtFromCase' => new ActionAnalysis(throws: [$thrown('ExportFaultResponseException')]),
        'factory' => new ActionAnalysis(throws: [$thrown('ExportRefusedException')]),
        'staticFactory' => new ActionAnalysis(throws: [$thrown('ExportRefusedException')]),
    ];
}

/** @return callable(): TypeEngine */
function relayedResponseEngine(): callable
{
    $analyses = [];
    foreach (relayedResponseAnalyses() as $action => $analysis) {
        $analyses[RelayedResponseController::class.'::'.$action] = $analysis;
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function relayedResponseRoutes(): callable
{
    return static function (Router $router): void {
        foreach (array_keys(relayedResponseAnalyses()) as $action) {
            $router->post('api/relayed/'.$action, [RelayedResponseController::class, $action]);
        }
    };
}

it('publishes the response an application’s own response exception carries, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(relayedResponseRoutes(), relayedResponseRoutes(), relayedResponseEngine());

    assertGolden('workbench-relayed-response.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $actions = array_keys(relayedResponseAnalyses());
    $real = FixtureRunner::analyzeMany(
        'app/Http/Controllers/RelayedResponseController.php',
        'App\\Http\\Controllers\\RelayedResponseController',
        $actions,
    );

    // What reaches the document: every return's type, and each throw it publishes, under the class's name in
    // the application that threw it.
    $facts = static fn (ActionAnalysis $analysis, string $app): array => [
        array_map(static fn (ReturnSite $site): array => $site->type->toArray(), $analysis->returns),
        array_values(array_map(
            static fn (ThrownException $throw): string => str_replace($app, '', $throw->identityKey()),
            array_filter($analysis->throws, static fn (ThrownException $throw): bool => $throw->disposition === ThrowDisposition::Signal),
        )),
    ];

    foreach (relayedResponseAnalyses() as $action => $scripted) {
        /** @var array<string, mixed> $recovered */
        $recovered = $real[$action];

        expect($facts(ActionAnalysis::fromArray($recovered), 'App\\'))->toBe($facts($scripted, 'Workbench\\App\\'), $action);
    }
})->group('fixture');
