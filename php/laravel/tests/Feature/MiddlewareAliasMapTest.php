<?php

declare(strict_types=1);

use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Routing\LaravelRouteResolver;
use Docuccino\Laravel\Support\AuthMiddlewareNames;
use Docuccino\Laravel\Tests\Fixtures\Middleware\ApplicationAuthenticate;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * An application that registers `auth` against its OWN `Authenticate` subclass — the Laravel ≤10
 * skeleton's default, carried into every application upgraded from one. The alias map is the
 * application's, not the framework's, and reading the framework's instead is wrong in both directions
 * at once: the subclass spelled by class name is a middleware nobody recognises, so the route is
 * published PUBLIC ({@see AuthMiddlewareNames}); and the framework's own
 * authenticator is no longer what `auth` resolves to, so an exclusion naming it removes nothing —
 * while a document that subtracted it anyway drops a 401 the server does enforce.
 */
beforeEach(function (): void {
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->aliasMiddleware('auth', ApplicationAuthenticate::class);

    // The application's own authenticator, written the way its static constructor renders it.
    $router->get('api/aliased/by-app-class', [FormController::class, 'index'])
        ->middleware(ApplicationAuthenticate::using('web'));
    // The alias, excluded by the class it is registered against: the framework drops it.
    $router->get('api/aliased/excluded-by-app-class', [FormController::class, 'index'])
        ->middleware('auth:web')
        ->withoutMiddleware(ApplicationAuthenticate::using('web'));
    // The alias, excluded by the framework class it is NOT registered against: the framework keeps it,
    // so the route still authenticates and still owes its 401.
    $router->get('api/aliased/excluded-by-framework-class', [FormController::class, 'index'])
        ->middleware('auth:web')
        ->withoutMiddleware(Authenticate::using('web'));
    $router->getRoutes()->refreshNameLookups();
});

it('subtracts a route\'s middleware through the application\'s alias map, not the framework\'s', function (): void {
    $document = app(DocumentConfigFactory::class)
        ->make('default', (array) config('docuccino.documents.default'), 'skeleton');

    $middleware = [];
    foreach (app(LaravelRouteResolver::class)->resolve($document) as $descriptor) {
        $middleware[$descriptor->uri] = $descriptor->middleware;
    }

    expect($middleware['/api/aliased/by-app-class'] ?? null)->toBe([ApplicationAuthenticate::class.':web'])
        ->and($middleware['/api/aliased/excluded-by-app-class'] ?? null)->toBe([])
        ->and($middleware['/api/aliased/excluded-by-framework-class'] ?? null)->toBe(['auth:web']);
});

/** The same three rows against the authority: whatever the framework's router keeps, we keep. */
it('agrees with the framework\'s own router under an application alias', function (): void {
    /** @var Router $router */
    $router = app('router');
    $aliases = $router->getMiddleware();
    $groups = $router->getMiddlewareGroups();

    $document = app(DocumentConfigFactory::class)
        ->make('default', (array) config('docuccino.documents.default'), 'skeleton');

    $middleware = [];
    foreach (app(LaravelRouteResolver::class)->resolve($document) as $descriptor) {
        $middleware[$descriptor->uri] = $descriptor->middleware;
    }

    $identities = static function (array $names) use ($aliases, $groups): array {
        $out = [];
        foreach ($names as $name) {
            foreach ((array) MiddlewareNameResolver::resolve($name, $aliases, $groups) as $resolved) {
                if (is_string($resolved)) {
                    $out[] = ltrim($resolved, '\\');
                }
            }
        }
        sort($out);

        return array_values(array_unique($out));
    };

    $compared = 0;
    foreach ($router->getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');
        if (! str_starts_with($uri, '/api/aliased/')) {
            continue;
        }

        $compared++;
        expect($identities($middleware[$uri] ?? []))
            ->toBe($identities(array_filter($router->gatherRouteMiddleware($route), 'is_string')), $uri);
    }

    expect($compared)->toBe(3);
});

/**
 * And what the document publishes, which is the reason any of it matters: the two routes the framework
 * still authenticates carry the 401 and the security requirement, and the one it does not carries
 * neither. Both directions in one document, so neither half can pass on a document that publishes no
 * requirements at all.
 */
it('publishes the 401 and the requirement for the routes the application really authenticates', function (): void {
    $document = generateDocument(static function (array $raw): array {
        $raw['security'] = [
            'schemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
            'auto_detect_middleware' => 'auth*',
            'default' => [['bearer' => []]],
        ];

        return $raw;
    })->document->toArray();

    foreach (['/api/aliased/by-app-class', '/api/aliased/excluded-by-framework-class'] as $uri) {
        $operation = $document['paths'][$uri]['get'];
        expect($operation['responses'])->toHaveKey('401')
            ->and($operation['security'])->toBe([['bearer' => []]]);
    }

    $optedOut = $document['paths']['/api/aliased/excluded-by-app-class']['get'];
    expect($optedOut['responses'])->not->toHaveKey('401')
        ->and($optedOut)->not->toHaveKey('security');
});
