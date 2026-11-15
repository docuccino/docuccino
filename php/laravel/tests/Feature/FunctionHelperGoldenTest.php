<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FunctionHelperController;

/**
 * The document an application gets for helpers it writes as functions rather than methods: global ones
 * from its own `helpers.php` and namespaced ones, guarding an action that otherwise answers 200 or building
 * the response it returns. Byte-locked, warm as well as cold; the scripted analyses are what the real engine
 * recovers from the fixture app's same calls, which the last test holds them to.
 *
 * @return array<string, ActionAnalysis>
 */
function functionHelperAnalyses(): array
{
    $at = static fn (DType $type): ReturnSite => new ReturnSite($type, new SourceLocation(''));
    $json = static fn (string $key, DType $value, int $status): ClassT => new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([new ArrayShapeField($key, $value)]),
        new LiteralT($status),
    ]);

    $ok = $at($json('ok', new LiteralT(true), 200));
    $bare = $at(new ClassT('Illuminate\\Http\\JsonResponse'));

    return [
        'globalHttp' => new ActionAnalysis(returns: [$ok]),
        'globalAbort' => new ActionAnalysis(returns: [$ok]),
        'globalCustom' => new ActionAnalysis(returns: [$ok]),
        'globalValidation' => new ActionAnalysis(returns: [$ok]),
        'globalCarried' => new ActionAnalysis(returns: [$ok]),
        'globalGone' => new ActionAnalysis(returns: [$bare]),
        'globalFault' => new ActionAnalysis(returns: [$bare]),
        'namespacedNested' => new ActionAnalysis(returns: [$ok]),
        'namespacedGone' => new ActionAnalysis(returns: [$bare]),
        'namespacedFault' => new ActionAnalysis(returns: [$bare]),
    ];
}

/** @return callable(): TypeEngine */
function functionHelperEngine(): callable
{
    $analyses = [];
    foreach (functionHelperAnalyses() as $action => $analysis) {
        $analyses[FunctionHelperController::class.'::'.$action] = $analysis;
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function functionHelperRoutes(): callable
{
    return static function (Router $router): void {
        foreach (array_keys(functionHelperAnalyses()) as $action) {
            $router->post('api/helped/'.$action, [FunctionHelperController::class, $action]);
        }
    };
}

it('publishes what a helper function refuses or answers with, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(functionHelperRoutes(), functionHelperRoutes(), functionHelperEngine());

    assertGolden('workbench-function-helpers.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = FixtureRunner::analyzeMany(
        'app/Http/Controllers/FunctionHelperController.php',
        'App\\Http\\Controllers\\FunctionHelperController',
        array_keys(functionHelperAnalyses()),
    );

    // What reaches the document: every return's type, and each throw it publishes. The fixture app's
    // classes are the workbench's under another namespace.
    $facts = static fn (ActionAnalysis $analysis): array => [
        array_map(static fn (ReturnSite $site): array => $site->type->toArray(), $analysis->returns),
        array_values(array_map(
            static fn (ThrownException $throw): string => str_replace('Workbench\\App\\', 'App\\', $throw->identityKey()).' '.$throw->confidence->value,
            array_filter($analysis->throws, static fn (ThrownException $throw): bool => $throw->disposition === ThrowDisposition::Signal),
        )),
    ];

    foreach (functionHelperAnalyses() as $action => $scripted) {
        /** @var array<string, mixed> $recovered */
        $recovered = $real[$action];

        expect($facts(ActionAnalysis::fromArray($recovered)))->toBe($facts($scripted), $action);
    }
})->group('fixture');
