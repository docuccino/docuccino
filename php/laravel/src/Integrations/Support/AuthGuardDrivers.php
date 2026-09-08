<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Laravel\Support\AuthMiddlewareNames;

/**
 * Resolves a route's `auth`/`auth:<guard>` middleware — in either spelling, alias or class name — to the
 * DRIVERS behind those guards, via the app's
 * `config('auth.guards')` map. The driver, not the guard name, is what tells you which security
 * integration owns a route: a `passport`-driver guard is Passport whatever it's called, and an `api`
 * guard on a `sanctum` driver isn't. The extension resolves the config and passes the plain map in, so
 * this stays pure and dataset-testable.
 */
final class AuthGuardDrivers
{
    /**
     * The drivers the middleware resolve to, deduped in first-seen order. A guard missing from the map
     * contributes nothing.
     *
     * @param  list<string>  $middleware
     * @param  array<string, string>  $drivers  guard name → driver
     * @return list<string>
     */
    public static function driversFor(array $middleware, array $drivers, string $defaultGuard): array
    {
        $result = [];
        foreach ($middleware as $entry) {
            foreach (self::guardsFor($entry, $defaultGuard) as $guard) {
                $driver = $drivers[$guard] ?? null;
                if ($driver !== null && ! in_array($driver, $result, true)) {
                    $result[] = $driver;
                }
            }
        }

        return $result;
    }

    /**
     * The guard→driver map from a raw `config('auth.guards')` value, malformed entries dropped.
     *
     * @return array<string, string>
     */
    public static function map(mixed $guards): array
    {
        if (! is_array($guards)) {
            return [];
        }

        $map = [];
        foreach ($guards as $name => $config) {
            if (is_string($name) && is_array($config) && isset($config['driver']) && is_string($config['driver'])) {
                $map[$name] = $config['driver'];
            }
        }

        return $map;
    }

    /**
     * The guards an entry names: the default for bare `auth`, the comma list for `auth:a,b`, none
     * otherwise.
     *
     * @return list<string>
     */
    private static function guardsFor(string $entry, string $defaultGuard): array
    {
        // Both spellings of the authenticator, read through the one list of them: a `passport` guard
        // written `Authenticate::using('api')` names the same guard `auth:api` does, and a reader that
        // knew only the alias resolved it to no driver at all — so the integration that owns the route
        // never claimed it and the document published it as public.
        $arguments = AuthMiddlewareNames::guardArguments($entry);
        if ($arguments === null) {
            return [];
        }

        $named = array_values(array_filter(
            array_map('trim', explode(',', $arguments)),
            static fn (string $guard): bool => $guard !== '',
        ));

        // A bare authenticator names the default guard; an argument list that named none — `auth:` —
        // names nothing. Only a bare entry can lack the separator, in either spelling.
        return $named === [] && ! str_contains($entry, ':') ? [$defaultGuard] : $named;
    }

    /** The default guard from `config('auth.defaults.guard')`; Laravel's own fallback is `web`. */
    public static function defaultGuard(mixed $configured): string
    {
        return is_string($configured) && $configured !== '' ? $configured : 'web';
    }
}
