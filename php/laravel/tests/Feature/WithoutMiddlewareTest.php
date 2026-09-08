<?php

declare(strict_types=1);

use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Routing\LaravelRouteResolver;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * `withoutMiddleware(...)` exclusions (arch/qa §1.2): the route resolver mirrors Laravel's own
 * Router — an excluded middleware is removed from the gathered set, and one the Router does NOT
 * consider excluded stays — so a route that opts out of `throttle`/`auth` is not documented with the
 * 429/401 (or the security requirement) it never enforces, and one that opts out in a spelling the
 * framework does not equate keeps the 401 it does. Regression guard for the historically-ignored
 * `$route->excludedMiddleware()`.
 */
beforeEach(function (): void {
    bindStubEngine();

    $router = app('router');
    // Rate-limited but the throttle is explicitly excluded → no 429 should be documented.
    $router->get('api/opt-out-throttle', [FormController::class, 'index'])
        ->middleware('throttle:60,1')
        ->withoutMiddleware('throttle:60,1');
    // Authenticated but the auth guard is explicitly excluded → no 401 and no security requirement.
    $router->get('api/opt-out-auth', [FormController::class, 'index'])
        ->middleware('auth:web')
        ->withoutMiddleware('auth:web');
    // The same opt-out written in the OTHER spelling of the same middleware, both ways round. Laravel
    // resolves both sides through its alias map before subtracting, so at runtime each of these really
    // is unauthenticated — subtracting by the literal string left them carrying a 401 nothing enforces.
    $router->get('api/opt-out-auth-by-class', [FormController::class, 'index'])
        ->middleware('auth:web')
        ->withoutMiddleware(Authenticate::using('web'));
    $router->get('api/opt-out-auth-by-alias', [FormController::class, 'index'])
        ->middleware(Authenticate::using('web'))
        ->withoutMiddleware('auth:web');
    // One exclusion out of two middleware: the OTHER one has to survive. Nothing but a route carrying
    // two of them can tell a subtraction from a route that simply gathered nothing.
    $router->get('api/opt-out-one-of-two', [FormController::class, 'index'])
        ->middleware(['auth:web', 'throttle:60,1'])
        ->withoutMiddleware('throttle:60,1');
    // The same cross-spelling subtraction for the middleware that are not the authenticator: the
    // framework resolves these two sides to one class as well, so a document keeping them publishes a
    // 429 and a 403 the server never returns.
    $router->get('api/opt-out-throttle-by-class', [FormController::class, 'index'])
        ->middleware('throttle:60,1')
        ->withoutMiddleware(ThrottleRequests::class.':60,1');
    $router->get('api/opt-out-can-by-class', [FormController::class, 'index'])
        ->middleware(['auth:web', 'can:view'])
        ->withoutMiddleware(Authorize::using('view'));
    // And the two the framework does NOT equate: it compares its resolved names with the arguments
    // still attached, so `Authenticate` and `Authenticate:` are two middleware to it and neither of
    // these routes loses its authenticator. Reading both as "no arguments" dropped a 401 the server
    // does enforce.
    $router->get('api/opt-out-auth-empty-args', [FormController::class, 'index'])
        ->middleware('auth')
        ->withoutMiddleware('auth:');
    $router->get('api/opt-out-auth-bare', [FormController::class, 'index'])
        ->middleware('auth:')
        ->withoutMiddleware('auth');
    $router->getRoutes()->refreshNameLookups();
});

it('drops excluded middleware from the resolved route descriptor', function (): void {
    $document = app(DocumentConfigFactory::class)
        ->make('default', (array) config('docuccino.documents.default'), 'skeleton');

    $middlewareByUri = [];
    foreach (app(LaravelRouteResolver::class)->resolve($document) as $descriptor) {
        $middlewareByUri[$descriptor->uri] = $descriptor->middleware;
    }

    expect($middlewareByUri['/api/opt-out-throttle'] ?? null)->not->toContain('throttle:60,1')
        ->and($middlewareByUri['/api/opt-out-auth'] ?? null)->not->toContain('auth:web')
        ->and($middlewareByUri['/api/opt-out-auth-by-class'] ?? null)->toBe([])
        ->and($middlewareByUri['/api/opt-out-auth-by-alias'] ?? null)->toBe([])
        ->and($middlewareByUri['/api/opt-out-throttle-by-class'] ?? null)->toBe([])
        // The survivor, named: an exclusion removes the middleware it names and nothing else.
        ->and($middlewareByUri['/api/opt-out-one-of-two'] ?? null)->toBe(['auth:web'])
        ->and($middlewareByUri['/api/opt-out-can-by-class'] ?? null)->toBe(['auth:web'])
        // And the two the framework keeps.
        ->and($middlewareByUri['/api/opt-out-auth-empty-args'] ?? null)->toBe(['auth'])
        ->and($middlewareByUri['/api/opt-out-auth-bare'] ?? null)->toBe(['auth:']);
});

/**
 * The subtraction against the authority it is a mirror of: whatever the framework's own
 * `Router::gatherRouteMiddleware()` keeps for each of these routes, we keep — read back through the
 * framework's own resolver so the comparison is in ITS vocabulary and not in one of ours. Compared as a
 * set of class identities, because the short forms we hand on are deliberately canonicalised (a leading
 * `\` names the same class, and an aliased class comes back under its alias).
 */
it('keeps exactly what the framework\'s own router keeps', function (): void {
    /** @var Router $router */
    $router = app('router');
    $aliases = $router->getMiddleware();
    $groups = $router->getMiddlewareGroups();

    $identities = static function (array $names) use ($aliases, $groups): array {
        $out = [];
        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }
            foreach ((array) MiddlewareNameResolver::resolve($name, $aliases, $groups) as $resolved) {
                if (is_string($resolved)) {
                    $out[] = ltrim($resolved, '\\');
                }
            }
        }
        sort($out);

        return array_values(array_unique($out));
    };

    $document = app(DocumentConfigFactory::class)
        ->make('default', (array) config('docuccino.documents.default'), 'skeleton');

    $ours = [];
    foreach (app(LaravelRouteResolver::class)->resolve($document) as $descriptor) {
        $ours[$descriptor->uri] = $descriptor->middleware;
    }

    $compared = 0;
    foreach ($router->getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');
        if (! str_starts_with($uri, '/api/opt-out-') || ! array_key_exists($uri, $ours)) {
            continue;
        }

        $compared++;
        expect($identities($ours[$uri]))->toBe($identities($router->gatherRouteMiddleware($route)), $uri);
    }

    // A scan that stopped seeing its routes must fail rather than pass.
    expect($compared)->toBeGreaterThanOrEqual(9);
});

it('documents no 429 for a route that excludes its throttle middleware', function (string $uri): void {
    $document = generateDocument()->document->toArray();

    expect($document['paths'][$uri]['get']['responses'])->not->toHaveKey('429');
})->with([
    'same spelling both sides' => ['/api/opt-out-throttle'],
    'excluded by the class name' => ['/api/opt-out-throttle-by-class'],
]);

it('documents no 403 for a route that excludes its authorization middleware', function (): void {
    $document = generateDocument()->document->toArray();

    expect($document['paths']['/api/opt-out-can-by-class']['get']['responses'])->not->toHaveKey('403');
});

/**
 * The published 401 and security requirement, both directions in ONE document: the routes that opted
 * out carry neither, and the route whose exclusion named its OTHER middleware carries both. Indexed
 * rather than defaulted — a `?? []` here passes for a route that was never registered at all — and the
 * document is given a `security.default` so the requirement half is a real assertion instead of a fact
 * about a document that publishes no requirements anywhere.
 */
it('publishes the 401 and the security requirement for exactly the routes that still enforce them', function (): void {
    $document = generateDocument(static function (array $raw): array {
        $raw['security'] = [
            'schemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
            'auto_detect_middleware' => 'auth*',
            'default' => [['bearer' => []]],
        ];

        return $raw;
    })->document->toArray();

    $unauthenticated = ['/api/opt-out-auth', '/api/opt-out-auth-by-class', '/api/opt-out-auth-by-alias'];
    foreach ($unauthenticated as $uri) {
        $operation = $document['paths'][$uri]['get'];
        expect($operation['responses'])->not->toHaveKey('401')
            ->and($operation)->not->toHaveKey('security');
    }

    $authenticated = [
        // The exclusion named the throttle, so the authenticator is untouched.
        '/api/opt-out-one-of-two',
        '/api/opt-out-can-by-class',
        // And the two spellings the framework does not equate.
        '/api/opt-out-auth-empty-args',
        '/api/opt-out-auth-bare',
    ];
    foreach ($authenticated as $uri) {
        $operation = $document['paths'][$uri]['get'];
        expect($operation['responses'])->toHaveKey('401')
            ->and($operation['security'])->toBe([['bearer' => []]]);
    }
});

/**
 * The same answer for a router the HTTP kernel has never synced, which is the state a console build
 * finds: the alias map is empty until that constructor runs. A reader of the router's own map alone
 * stops equating the two spellings of one middleware there, and the workbench's `api/unguarded-forms`
 * — which opts out of its authenticator in the other spelling — gets back a 401 it does not enforce.
 */
it('answers the same for a router the HTTP kernel has never synced', function (): void {
    $this->refreshApplication();
    bindStubEngine();

    // The premise, from the framework: this really is what a console build reads.
    expect(app('router')->getMiddleware())->toBe([]);

    $paths = generateDocument()->document->toArray()['paths'];

    expect($paths['/api/unguarded-forms']['get']['responses'])->not->toHaveKey('401')
        // Anti-vacuity: this document does publish 401s, on the route beside it whose authenticator
        // nothing excluded.
        ->and($paths['/api/guarded-forms']['get']['responses'])->toHaveKey('401');
});
