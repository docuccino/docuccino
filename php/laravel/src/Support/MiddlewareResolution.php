<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Extensions\Context\RouteDescriptor;

/**
 * A route's middleware set the way the framework's own `Router::resolveMiddleware()` reads it: every
 * entry resolved through the application's alias map before an excluded one is subtracted, and the
 * survivors handed back in the short-form vocabulary the detectors speak.
 *
 * Both halves need the alias map, and both were wrong without it. An application may register `auth`
 * against its OWN `Authenticate` subclass — the Laravel ≤10 skeleton does, and every app upgraded from
 * one carries it — and then `auth:web` and `Illuminate\Auth\Middleware\Authenticate:web` are two
 * different middleware while `auth:web` and `App\Http\Middleware\Authenticate:web` are one. A reader
 * holding the framework's map instead of the application's gets both of those backwards, in the
 * direction that drops a 401 the server does enforce.
 *
 * The alias map reaches the published document only through the middleware list this returns, and that
 * list is folded into {@see RouteDescriptor::cacheSignature()}
 * verbatim — so a map edited in a service provider invalidates exactly the fragments whose middleware
 * it changed, and needs no environment digest of its own.
 *
 * Pure: the map is passed in, so the resolution is dataset-testable. `class_exists()` here autoloads
 * the same middleware classes the framework's own subtraction does, and only for a route that excludes
 * something.
 */
final class MiddlewareResolution
{
    /**
     * The gathered entries an exclusion does not remove, mirroring `Router::resolveMiddleware()`: an
     * entry is dropped when its resolved name matches an excluded resolved name exactly, or — for a
     * name that is a class with no arguments — when it is a subclass of one. Both lists must already
     * have their groups expanded, as the framework's do by this point.
     *
     * Matching in the framework's resolved-class space rather than on the literal strings is what makes
     * `withoutMiddleware(Authorize::using('view'))` remove a group's `can:view`, and what keeps
     * `withoutMiddleware('auth:')` from removing a bare `auth` the framework keeps.
     *
     * @param  list<string>  $gathered
     * @param  list<string>  $excluded
     * @param  array<array-key, mixed>  $aliases  the router's alias map, alias → class
     * @return list<string>
     */
    public static function subtract(array $gathered, array $excluded, array $aliases): array
    {
        if ($excluded === []) {
            return $gathered;
        }

        $resolvedExcluded = [];
        foreach ($excluded as $entry) {
            $resolvedExcluded[] = self::resolve($entry, $aliases);
        }

        $kept = [];
        foreach ($gathered as $entry) {
            $resolved = self::resolve($entry, $aliases);

            if (in_array($resolved, $resolvedExcluded, true) || self::subclassOfAny($resolved, $resolvedExcluded)) {
                continue;
            }

            $kept[] = $entry;
        }

        return $kept;
    }

    /**
     * An entry in the alias vocabulary wherever the application registered one for its class: the
     * inverse of the framework's own alias resolution, arguments preserved.
     *
     * Every reader downstream speaks that vocabulary — a `security.auto_detect_middleware` pattern is
     * written in it, and so is every short form the parsers match — so a route naming a middleware by
     * class is turned back into the name it was registered under here, once, rather than each reader
     * carrying the application's map. An entry with no registered alias is returned unchanged, which is
     * what leaves the framework's own family to {@see AuthMiddlewareNames}.
     *
     * @param  array<array-key, mixed>  $aliases  the router's alias map, alias → class
     */
    public static function canonical(string $entry, array $aliases): string
    {
        foreach ($aliases as $alias => $class) {
            if (! is_string($alias) || ! is_string($class)) {
                continue;
            }

            $arguments = MiddlewareName::arguments($entry, $class);
            if ($arguments === null) {
                continue;
            }

            return $alias.(MiddlewareName::bare($entry) ? '' : ':'.$arguments);
        }

        return $entry;
    }

    /**
     * `MiddlewareNameResolver::resolve()` for an entry whose groups are already expanded: the alias's
     * class with the arguments reattached, or the entry itself where no alias answers.
     *
     * @param  array<array-key, mixed>  $aliases
     */
    private static function resolve(string $entry, array $aliases): string
    {
        [$name, $arguments] = array_pad(explode(':', $entry, 2), 2, null);
        $class = $aliases[$name] ?? null;

        return (is_string($class) ? $class : (string) $name).($arguments === null ? '' : ':'.$arguments);
    }

    /**
     * The framework's subclass fallback, gate included: it asks `class_exists()` first, so a resolved
     * name carrying arguments never reaches it — which is why `withoutMiddleware(Authenticate::class)`
     * drops a bare subclass entry and leaves an `auth:web` one alone.
     *
     * @param  list<string>  $excluded
     */
    private static function subclassOfAny(string $resolved, array $excluded): bool
    {
        if (! class_exists($resolved)) {
            return false;
        }

        foreach ($excluded as $entry) {
            if (class_exists($entry) && is_subclass_of($resolved, $entry)) {
                return true;
            }
        }

        return false;
    }
}
