<?php

declare(strict_types=1);

use Docuccino\Laravel\Support\MiddlewareAliases;
use Docuccino\Laravel\Support\MiddlewareResolution;
use Docuccino\Laravel\Tests\Fixtures\Middleware\ApplicationAuthenticate;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Router;

/**
 * Where the alias map comes from. A router carries none until the HTTP kernel is constructed, and a
 * documentation build usually runs from the console, where nothing constructs it — so a reader of the
 * router's map alone is blind in exactly the context the product runs in.
 */
it('answers the framework\'s own default aliases for a router nothing has synced', function (): void {
    $bare = new Router(new Dispatcher);

    // The premise, stated from the framework: this really is the state a console build finds.
    expect($bare->getMiddleware())->toBe([]);

    $aliases = MiddlewareAliases::of($bare);

    // Read against the source of truth rather than a copy of it, and asserted as the whole table so a
    // default the framework adds or renames cannot leave this short.
    expect($aliases)->toBe((new Middleware)->getMiddlewareAliases())
        ->and($aliases)->toHaveKey('auth')
        ->and($aliases['auth'])->toBe(Authenticate::class);
});

/**
 * And what that fallback is FOR: without it the subtraction stops equating the two spellings of one
 * middleware in a console build, so a route that opts out of its authenticator keeps a 401 it does not
 * enforce — the defect this stack fixed, alive again in the context that matters most.
 */
it('equates the two spellings of one middleware for an unsynced router', function (): void {
    $aliases = MiddlewareAliases::of(new Router(new Dispatcher));

    expect(MiddlewareResolution::subtract(['auth:web'], [Authenticate::using('web')], $aliases))->toBe([])
        ->and(MiddlewareResolution::canonical(Authenticate::using('web'), $aliases))->toBe('auth:web');
});

it('lets an alias the application registered win over the default it replaces', function (): void {
    $router = new Router(new Dispatcher);
    $router->aliasMiddleware('auth', ApplicationAuthenticate::class);
    $router->aliasMiddleware('tenant', 'Docuccino\\Laravel\\Tests\\Fixtures\\Middleware\\MergesATenant');

    $aliases = MiddlewareAliases::of($router);

    expect($aliases['auth'])->toBe(ApplicationAuthenticate::class)
        ->and($aliases)->toHaveKey('tenant')
        // …and the defaults it did not touch are still there.
        ->and($aliases['can'])->toBe(Authorize::class);
});
