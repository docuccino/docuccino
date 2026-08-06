<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\InferredHandler\HandlerReflector;
use Docuccino\Laravel\Integrations\InferredHandler\RenderCallback;
use Docuccino\Laravel\Tests\Support\DecoratingExceptionHandler;
use Docuccino\Laravel\Tests\Support\InvokableRenderer;
use Docuccino\Laravel\Tests\Support\PairRenderer;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The reflector must discover EVERY shape of registered render callback Laravel stores, and never drop
 * one in silence. Laravel wraps every non-Closure render callable via `Closure::fromCallable()`, so an
 * invokable renderer, an `[$object, 'method']` pair, and a first-class callable all arrive as closures
 * naming a real method — those must be reported as the method they are (class + method), while a genuine
 * anonymous closure keeps its by-line locator. A decorator around the handler (Collision in console)
 * must be walked through, and an unanalysable callback recorded rather than dropped.
 */

/** Register a callback on the app handler and return the render callback the reflector newly discovered for it. */
function reflectNewlyRegistered(callable $callback): RenderCallback
{
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable($callback);

    // Laravel appends in registration order, so the one just registered is last.
    $callbacks = (new HandlerReflector($handler))->renderCallbacks();

    return $callbacks[array_key_last($callbacks)];
}

it('discovers each Laravel render-callable form under its real analysis target', function (string $form, ?string $class, ?string $method): void {
    $callable = match ($form) {
        'anonymous' => static fn (ModelNotFoundException $e) => response()->json(['error' => 'gone'], 410),
        'invokable' => Closure::fromCallable(new InvokableRenderer),
        'pair' => Closure::fromCallable([new PairRenderer, 'handle']),
        'first-class' => (new PairRenderer)->handle(...),
    };

    $callback = reflectNewlyRegistered($callable);

    expect($callback->exceptionType)->toBe(ModelNotFoundException::class)
        ->and($callback->parameterName)->toBe('e')
        ->and($callback->isMethod())->toBe($method !== null)
        ->and($callback->class)->toBe($class)
        ->and($callback->method)->toBe($method);
})->with([
    'anonymous closure (by-line)' => ['anonymous', null, null],
    'invokable object (__invoke method)' => ['invokable', InvokableRenderer::class, '__invoke'],
    '[object, method] pair' => ['pair', PairRenderer::class, 'handle'],
    'first-class callable' => ['first-class', PairRenderer::class, 'handle'],
]);

it('keeps an anonymous closure on its by-line locator (the closure start line)', function (): void {
    $closure = static fn (ModelNotFoundException $e) => response()->json(['error' => 'gone'], 410);
    $expectedLine = (new ReflectionFunction($closure))->getStartLine();

    $callback = reflectNewlyRegistered($closure);

    expect($callback->isMethod())->toBeFalse()
        ->and($callback->line)->toBe($expectedLine);
});

it('walks through a handler decorator to the wrapped handler that owns the callbacks', function (): void {
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable(Closure::fromCallable(new InvokableRenderer));

    // Wrap the real handler the way Collision's console adapter does — no renderCallbacks of its own.
    $decorated = new DecoratingExceptionHandler($handler);
    $callbacks = (new HandlerReflector($decorated))->renderCallbacks();

    $invokable = array_values(array_filter(
        $callbacks,
        static fn (RenderCallback $c): bool => $c->class === InvokableRenderer::class,
    ));

    expect($invokable)->toHaveCount(1)
        ->and($invokable[0]->method)->toBe('__invoke');
});

it('records an unanalysable render callback as skipped rather than dropping it silently', function (): void {
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    // A first parameter with a builtin type is not an exception the tier can bind — unanalysable.
    $handler->renderable(static fn (string $whoops) => response()->json([], 400));

    $reflector = new HandlerReflector($handler);

    expect($reflector->renderCallbacks())->toBe([])
        ->and($reflector->skipped())->toHaveCount(1)
        ->and($reflector->skipped()[0])->toContain('closure@');
});
