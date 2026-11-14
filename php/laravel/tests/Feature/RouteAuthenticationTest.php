<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Support\RouteAuthentication;
use Docuccino\Laravel\Tests\Fixtures\Middleware\ApplicationAuthenticate;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * {@see RouteAuthentication}'s table, row by row, and the reason each row is right: the middleware is run
 * against a guest's JSON request and refuses it with exactly the exception the table names.
 */
function routeAuthenticationContext(array $middleware, ?string $pattern = null): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/things', middleware: $middleware),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet([]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', [], authMiddleware: $pattern),
    );
}

/** @return array<class-string, class-string> */
function routeAuthenticationTable(): array
{
    /** @var array<class-string, class-string> */
    return (new ReflectionClassConstant(RouteAuthentication::class, 'REFUSALS'))->getValue();
}

/** What the middleware does with a guest's JSON request: the class of what it throws, or null if it lets it through. */
function guestRefusal(object $middleware): ?string
{
    $request = Request::create('/api/things', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    $request->setLaravelSession(app('session.store'));

    try {
        $middleware->handle($request, static fn (): Response => new Response);
    } catch (Throwable $e) {
        return $e::class;
    }

    return null;
}

it('names the exception each authenticator really throws at a guest', function (string $class): void {
    expect(guestRefusal(app($class)))->toBe(routeAuthenticationTable()[$class]);
})->with(array_keys(routeAuthenticationTable()));

it('lists the framework authenticators and nothing else', function (): void {
    expect(array_keys(routeAuthenticationTable()))->toBe([Authenticate::class, AuthenticateWithBasicAuth::class]);
});

it('leaves out auth.session, which lets a guest through', function (): void {
    expect(guestRefusal(app(AuthenticateSession::class)))->toBeNull()
        ->and(app(RouteAuthentication::class)->refusal(routeAuthenticationContext(['auth.session'])))->toBeNull();
});

it('answers every spelling of an authenticator with its exception', function (string $middleware, string $exception): void {
    expect(app(RouteAuthentication::class)->refusal(routeAuthenticationContext([$middleware])))->toBe($exception);
})->with([
    'auth' => ['auth', 'Illuminate\\Auth\\AuthenticationException'],
    'auth:sanctum' => ['auth:sanctum', 'Illuminate\\Auth\\AuthenticationException'],
    'Authenticate::using()' => [Authenticate::using('api'), 'Illuminate\\Auth\\AuthenticationException'],
    "the application's own subclass by class name" => [ApplicationAuthenticate::class, 'Illuminate\\Auth\\AuthenticationException'],
    'auth.basic' => ['auth.basic', 'Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException'],
    'AuthenticateWithBasicAuth by class' => [AuthenticateWithBasicAuth::class, 'Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException'],
]);

it('answers for the first authenticator the route runs', function (): void {
    expect(app(RouteAuthentication::class)->refusal(routeAuthenticationContext(['throttle:60,1', 'auth.basic', 'auth'])))
        ->toBe('Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException');
});

it('counts middleware the configured pattern names as an authenticator of its own', function (): void {
    $authentication = app(RouteAuthentication::class);

    expect($authentication->refusal(routeAuthenticationContext(['jwt:access'], 'jwt*')))->toBe('Illuminate\\Auth\\AuthenticationException')
        ->and($authentication->refusesGuests(routeAuthenticationContext(['jwt:access'], 'jwt*')))->toBeTrue()
        // Anti-vacuity: the same route with no pattern runs nothing this can name.
        ->and($authentication->refusal(routeAuthenticationContext(['jwt:access'])))->toBeNull()
        ->and($authentication->refusesGuests(routeAuthenticationContext(['jwt:access'])))->toBeFalse();
});

it('finds no authenticator on a route that runs none', function (array $middleware): void {
    expect(app(RouteAuthentication::class)->refusal(routeAuthenticationContext($middleware, 'auth*')))->toBeNull();
})->with([
    'no middleware' => [[]],
    'throttle and can' => [['throttle:60,1', 'can:view,post']],
    'an alias outside the pattern' => [['jwt:access']],
]);
