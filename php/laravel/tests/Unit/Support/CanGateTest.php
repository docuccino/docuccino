<?php

declare(strict_types=1);

use Docuccino\Laravel\Support\CanGate;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Kiosk;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Placard;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Routing\Route;

/**
 * The authorization middleware grammar, read the way Laravel writes it — BOTH ways: the `can` alias and
 * the middleware's own class name, which is what `Authorize::using()` renders. The last two tests are
 * the ones that matter, holding the parser to what the framework really writes rather than to a string
 * this suite typed out, because a guard that reads a different grammar from the thing it guards is a
 * hole.
 */
it('reads the ability and the arguments off a can: middleware', function (string $middleware, string $ability, array $arguments): void {
    $gate = CanGate::parse($middleware);

    expect($gate)->not->toBeNull()
        ->and($gate?->ability)->toBe($ability)
        ->and($gate?->arguments)->toBe($arguments);
})->with([
    'ability only' => ['can:viewAny', 'viewAny', []],
    'one class' => ['can:view,App\\Models\\Kiosk', 'view', ['App\\Models\\Kiosk']],
    'a route parameter' => ['can:view,kiosk', 'view', ['kiosk']],
    'two arguments' => ['can:transfer,App\\Models\\Kiosk,recipient', 'transfer', ['App\\Models\\Kiosk', 'recipient']],
    'a dashed ability' => ['can:view-any,App\\Models\\Kiosk', 'view-any', ['App\\Models\\Kiosk']],
    'spaces around the arguments' => ['can:view, App\\Models\\Kiosk ', 'view', ['App\\Models\\Kiosk']],
    'a trailing empty argument' => ['can:view,App\\Models\\Kiosk,', 'view', ['App\\Models\\Kiosk']],
    'the middleware class name' => ['Illuminate\\Auth\\Middleware\\Authorize:view,App\\Models\\Kiosk', 'view', ['App\\Models\\Kiosk']],
    'the middleware class name, ability only' => ['Illuminate\\Auth\\Middleware\\Authorize:viewAny', 'viewAny', []],
]);

it('answers the same middlewares to the signal question as to the parse', function (string $middleware): void {
    // One prefix list, one reader: the signal check and the gate reader used to spell `can:` out
    // separately, and only one of them learnt the second spelling.
    expect(CanGate::matches($middleware))->toBe(CanGate::parse($middleware) !== null);
})->with([
    'the can alias' => ['can:view,App\\Models\\Kiosk'],
    'the middleware class name' => ['Illuminate\\Auth\\Middleware\\Authorize:view,App\\Models\\Kiosk'],
    'another alias' => ['role:admin'],
    'a signed url' => ['signed'],
    'a prefix that only looks like one' => ['cancel:order'],
]);

it('reads no gate out of a middleware that is not one', function (string $middleware): void {
    expect(CanGate::parse($middleware))->toBeNull();
})->with([
    'another alias' => ['role:admin'],
    'a signed url' => ['signed'],
    'a prefix that only looks like one' => ['cancel:order'],
    'no ability at all' => ['can:'],
    'a blank ability' => ['can: '],
    'the middleware class name with no ability' => ['Illuminate\\Auth\\Middleware\\Authorize:'],
]);

it('tells a class argument from a route parameter the way the middleware does', function (): void {
    expect(CanGate::isClassName('App\\Models\\Kiosk'))->toBeTrue()
        ->and(CanGate::isClassName('kiosk'))->toBeFalse();
});

it('renders the gate as the route wrote it', function (string $middleware, string $described): void {
    expect(CanGate::parse($middleware)?->describe())->toBe($described);
})->with([
    'ability only' => ['can:viewAny', "->can('viewAny')"],
    'a class' => ['can:view,App\\Models\\Kiosk', "->can('view', App\\Models\\Kiosk::class)"],
    'a leading separator' => ['can:view,\\App\\Models\\Kiosk', "->can('view', App\\Models\\Kiosk::class)"],
    'a route parameter' => ['can:view,kiosk', "->can('view', 'kiosk')"],
    'both' => ['can:transfer,App\\Models\\Kiosk,recipient', "->can('transfer', App\\Models\\Kiosk::class, 'recipient')"],
]);

it('reads what Laravel\'s own Route::can() writes', function (): void {
    $router = app('router');

    $one = $router->get('api/can-grammar-one', fn (): array => [])->can('viewAny', Kiosk::class);
    $two = $router->get('api/can-grammar-two', fn (): array => [])->can('view', [Kiosk::class, Placard::class]);
    $none = $router->get('api/can-grammar-none', fn (): array => [])->can('viewAny');

    $gateOf = static function (Route $route): ?CanGate {
        foreach ($route->gatherMiddleware() as $middleware) {
            $gate = CanGate::parse(is_string($middleware) ? $middleware : '');
            if ($gate !== null) {
                return $gate;
            }
        }

        return null;
    };

    expect($gateOf($one)?->arguments)->toBe([Kiosk::class])
        ->and($gateOf($two)?->arguments)->toBe([Kiosk::class, Placard::class])
        ->and($gateOf($none)?->ability)->toBe('viewAny')
        ->and($gateOf($none)?->arguments)->toBe([]);
});

it('reads what Laravel\'s own Authorize::using() writes', function (): void {
    // The second spelling the framework ships, and the one no alias appears in — a route written this
    // way carried no gate at all as far as this parser was concerned.
    $middleware = Authorize::using('view', Kiosk::class, 'recipient');

    expect(CanGate::matches($middleware))->toBeTrue()
        ->and(CanGate::parse($middleware)?->ability)->toBe('view')
        ->and(CanGate::parse($middleware)?->arguments)->toBe([Kiosk::class, 'recipient']);
});
