<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Illuminate\Routing\Router;

/**
 * The alias → class map a route's middleware is resolved through: the framework's own default aliases,
 * with whatever the application registered on the router laid over them.
 *
 * The router's map alone is not enough, because it is EMPTY until the HTTP kernel is constructed —
 * that is what calls `syncMiddlewareToRouter()` — and a documentation build usually runs from the
 * console, where nothing resolves that kernel. Measured on a stock Laravel 12 application booted
 * through the console kernel: no aliases and no middleware groups at all, while the routes are all
 * there. So a reader of the router's map alone would answer `auth:web` and `Authenticate::using('web')`
 * are different middleware in exactly the context the product runs in, and a route that opts out of its
 * authenticator would keep the 401 it does not enforce.
 *
 * The framework's own table is read rather than copied, so it is a function of the version the
 * application resolved. `Illuminate\Foundation` ships only in `laravel/framework`, which has no split
 * package to depend on, so the class is named behind a `class_exists()` — an application without it
 * has no default aliases to find.
 *
 * What this cannot see is an alias the application itself registered — `auth` bound to its own
 * `Authenticate` subclass, say — during a build whose router was never synced. That is the framework's
 * own blind spot in that context (`Router::resolveMiddleware()` reads the same empty map), and the
 * framework's defaults are the best available reading of it.
 */
final class MiddlewareAliases
{
    /**
     * @return array<string, string>
     */
    public static function of(Router $router): array
    {
        $aliases = [];
        foreach ([...self::frameworkDefaults(), ...$router->getMiddleware()] as $alias => $class) {
            if (is_string($alias) && is_string($class)) {
                $aliases[$alias] = $class;
            }
        }

        return $aliases;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function frameworkDefaults(): array
    {
        $configuration = 'Illuminate\\Foundation\\Configuration\\Middleware';

        return class_exists($configuration) ? (new $configuration)->getMiddlewareAliases() : [];
    }
}
