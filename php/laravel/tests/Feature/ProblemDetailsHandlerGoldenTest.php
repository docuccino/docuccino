<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Integrations\InferredHandler\ReceivedException;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Workbench\App\Exceptions\OrderConflictException;
use Workbench\App\Exceptions\Rfc9457\ProblemCallbacks;
use Workbench\App\Exceptions\Rfc9457\ProblemException;
use Workbench\App\Http\Controllers\ProblemDetailsController;

/**
 * The document an application gets when one render callback answers every error with an RFC 9457 problem: a
 * static helper picks the problem with a `match` on the exception, and the framework sends it through the
 * problem's own `toResponse()` (`Exceptions\Rfc9457`). Byte-locked, warm as well as cold; the scripted
 * analyses are what the real engine recovers from the fixture app's same handler, which the last test holds
 * them to.
 *
 * Each action, and the throw the engine reads in it: `throw new …` of the class, and Laravel's own
 * `ValidationException::withMessages()`.
 *
 * @return array<string, array{class-string, int|null}>
 */
function problemDetailsThrows(): array
{
    return [
        'conflict' => [OrderConflictException::class, 500],
        'invalid' => [ValidationException::class, 422],
        'unauthenticated' => [AuthenticationException::class, 500],
        'missing' => [NotFoundHttpException::class, null],
        'carried' => [ProblemException::class, 500],
        'failed' => [RuntimeException::class, 500],
    ];
}

/** What the callback is read to answer each thrown class with. */
function problemDetailsResponse(string $thrown): DType
{
    return new ClassT('Illuminate\\Http\\JsonResponse', [
        new MapT(ScalarT::string(), new UnknownT('mixed')),
        new UnknownT('status not folded'),
        new LiteralT('application/problem+json'),
    ]);
}

/** The callback the workbench registers, under the ref the handler tier analyses it by for `$thrown`. */
function problemDetailsRef(Closure $callback, string $thrown): CallableRef
{
    $function = new ReflectionFunction($callback);

    return new CallableRef(
        (string) $function->getFileName(),
        null,
        null,
        $function->getStartLine(),
        $function->getParameters()[0]->getName(),
        ReceivedException::byRenderCallbacks($thrown),
    );
}

/** @return callable(): TypeEngine */
function problemDetailsEngine(Closure $callback): callable
{
    $analyses = [];
    foreach (problemDetailsThrows() as $action => [$thrown, $hint]) {
        $analyses[ProblemDetailsController::class.'::'.$action] = new ActionAnalysis(throws: [
            new ThrownException($thrown, $hint, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
        ]);
    }

    $callables = [];
    foreach (problemDetailsThrows() as [$thrown]) {
        $callables[problemDetailsRef($callback, $thrown)->symbol()] = new ActionAnalysis(returns: [
            new ReturnSite(problemDetailsResponse($thrown), new SourceLocation('')),
        ]);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make($callables, analysisOverrides: $analyses);
}

function problemDetailsCallback(): Closure
{
    $callback = (new ProblemCallbacks)->problem();
    assert($callback instanceof Closure);

    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable($callback);

    return $callback;
}

function problemDetailsRoutes(): callable
{
    return static function (Router $router): void {
        foreach (array_keys(problemDetailsThrows()) as $action) {
            $router->post('api/problems/'.$action, [ProblemDetailsController::class, $action]);
        }
    };
}

it('publishes the problem each error is answered with, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(problemDetailsRoutes(), problemDetailsRoutes(), problemDetailsEngine(problemDetailsCallback()));

    assertGolden('workbench-problem-details-handler.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same handler', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $line = 0;
    foreach (explode("\n", (string) file_get_contents(FixtureRunner::path('app/Exceptions/Rfc9457/ProblemCallbacks.php'))) as $index => $text) {
        if (str_contains($text, 'Problems::for($e)')) {
            $line = $index + 1;
        }
    }
    expect($line)->toBeGreaterThan(0);

    foreach (problemDetailsThrows() as [$thrown]) {
        $narrowed = str_replace('Workbench\\App\\', 'App\\', ReceivedException::byRenderCallbacks($thrown));
        $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
            'app/Exceptions/Rfc9457/ProblemCallbacks.php',
            '',
            '',
            line: $line,
            param: 'e',
            narrowType: $narrowed,
        ));

        expect(array_map(static fn (ReturnSite $site): array => $site->type->toArray(), $analysis->returns))
            ->toBe([problemDetailsResponse($thrown)->toArray()], $thrown);
    }
})->group('fixture');
