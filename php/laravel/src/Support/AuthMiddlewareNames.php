<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * The framework's authentication middleware, in every spelling a route can name it: the registered
 * alias, or the middleware's own class name — which is what `Authenticate::using('web')` renders and
 * what a route listing `AuthenticateSession::class` writes. Whether a route is authenticated is the one
 * fact that decides both the implicit 401 and the security requirement, so a reader that knows only the
 * alias publishes an authenticated route as public.
 *
 * Pure, so the family and the grammar over it are dataset-testable. The grammar itself is
 * {@see MiddlewareName}; this is the list it is read against, stated once because its consumers span
 * both sides of the Extensions/Integrations line.
 */
final class AuthMiddlewareNames
{
    /**
     * Alias => class for the framework's own `auth*` aliases (`Middleware::defaultAliases()`). The two
     * halves of each row are two spellings of ONE middleware, which is the whole reason this is a map
     * and not a list.
     */
    private const FAMILY = [
        'auth' => 'Illuminate\\Auth\\Middleware\\Authenticate',
        'auth.basic' => 'Illuminate\\Auth\\Middleware\\AuthenticateWithBasicAuth',
        'auth.session' => 'Illuminate\\Session\\Middleware\\AuthenticateSession',
    ];

    /**
     * Every spelling of one middleware entry: the entry itself, plus the same middleware written under
     * its other registered name, arguments preserved. An entry outside the family is its own only
     * spelling.
     *
     * This is what keeps a configured wildcard meaning the same thing whichever spelling a route used —
     * the pattern is matched against the whole set rather than against the one string the route happened
     * to carry, so `auth*` and a hand-narrowed `auth:api` both answer identically for `auth:api` and for
     * `Authenticate::using('api')`.
     *
     * @return list<string>
     */
    public static function spellings(string $middleware): array
    {
        foreach (self::FAMILY as $alias => $class) {
            $arguments = MiddlewareName::arguments($middleware, $alias, $class);
            if ($arguments === null) {
                continue;
            }

            $suffix = $arguments === '' ? '' : ':'.$arguments;

            return array_values(array_unique([$middleware, $alias.$suffix, $class.$suffix]));
        }

        return [$middleware];
    }

    /**
     * Whether an entry names authentication middleware at all: the `auth` alias with any arguments, any
     * `auth.<variant>` alias — the convention the framework's own `auth.basic` and `auth.session` follow,
     * and which an application's own guard variant follows too — or the class name of one of the family,
     * bare or with arguments.
     */
    public static function matches(string $middleware): bool
    {
        if ($middleware === 'auth'
            || str_starts_with($middleware, 'auth:')
            || str_starts_with($middleware, 'auth.')) {
            return true;
        }

        return MiddlewareName::matches($middleware, ...array_values(self::FAMILY));
    }

    /**
     * The arguments of the guard-selecting authenticator — the `auth` alias or `Authenticate` itself —
     * unsplit: the empty string for a bare entry that names the default guard, and null for anything
     * else. The `auth.basic`/`auth.session` variants take arguments of their own but are deliberately
     * not read here: they are not how a route names a guard.
     */
    public static function guardArguments(string $middleware): ?string
    {
        return MiddlewareName::arguments($middleware, 'auth', self::FAMILY['auth']);
    }
}
