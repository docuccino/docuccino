<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Closure;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;

/**
 * Whether a route refuses a request that carries no credentials, and with which exception. Read from the
 * middleware the route actually runs (groups expanded, `withoutMiddleware()` subtracted), by class through
 * the build's alias map, so an alias of the application's own or a subclass of the framework's
 * authenticator counts and an alias re-pointed elsewhere does not ({@see MiddlewareClasses}).
 *
 * The framework's authenticators refuse whatever the document's configuration says, so this does not
 * depend on `security.auth_middleware`. That pattern still counts where it matches: it is how an author
 * names an authenticator of their own ({@see AuthMiddlewareDetector}). An auth package's own middleware
 * counts where its integration is enabled for the document, which names each class that checks a token
 * itself and what it throws; which classes those are depends on the package's installed version, so the
 * integration owns the list.
 *
 * @phpstan-type Authenticators array<class-string, array{refusal: class-string, denial: class-string}>
 */
final class RouteAuthentication
{
    /**
     * Each framework middleware that refuses a guest, and what it throws. `auth.basic` fails through the
     * guard's `basic()`, which throws the HTTP exception rather than `AuthenticationException`.
     * `auth.session` is not here: it passes a guest through.
     */
    private const REFUSALS = [
        Authenticate::class => 'Illuminate\\Auth\\AuthenticationException',
        AuthenticateWithBasicAuth::class => 'Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException',
    ];

    /** What an authenticator the configured pattern names is taken to throw. */
    private const PATTERN_REFUSAL = 'Illuminate\\Auth\\AuthenticationException';

    /** @var Closure(DocumentConfig): Authenticators */
    private readonly Closure $authenticators;

    /**
     * @param  (Closure(DocumentConfig): Authenticators)|null  $authenticators  the middleware the document's
     *                                                                          enabled auth integrations name:
     *                                                                          what each throws at a request
     *                                                                          without a token, and at a token
     *                                                                          lacking a scope it names
     */
    public function __construct(private readonly MiddlewareClasses $middleware, ?Closure $authenticators = null)
    {
        $this->authenticators = $authenticators ?? static fn (DocumentConfig $document): array => [];
    }

    /**
     * The exception the route's first authenticator throws for a request without credentials, or null
     * where nothing on the route refuses one.
     */
    public function refusal(RouteContext $context): ?string
    {
        $refusals = self::REFUSALS;
        foreach (($this->authenticators)($context->document) as $class => $thrown) {
            $refusals[$class] = $thrown['refusal'];
        }

        foreach ($context->route->middleware as $middleware) {
            foreach ($refusals as $class => $exception) {
                if ($this->middleware->runs($context, $middleware, $class)) {
                    return $exception;
                }
            }

            if (AuthMiddlewareDetector::matchesEntry($context, $middleware)) {
                return self::PATTERN_REFUSAL;
            }
        }

        return null;
    }

    /** Whether a guest is refused before the action, and so before any gate on the route. */
    public function refusesGuests(RouteContext $context): bool
    {
        return $this->refusal($context) !== null;
    }

    /**
     * The exception one middleware entry throws for a token lacking a scope or ability the entry names,
     * or null. An entry naming none checks the token and nothing past it.
     */
    public function denial(RouteContext $context, string $middleware): ?string
    {
        if (MiddlewareName::bare($middleware)) {
            return null;
        }

        foreach (($this->authenticators)($context->document) as $class => $thrown) {
            if ($this->middleware->runs($context, $middleware, $class)) {
                return $thrown['denial'];
            }
        }

        return null;
    }
}
