<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Extensions\Context\RouteContext;

/**
 * "Is this route behind auth middleware": does any of its middleware match the document's
 * `security.auto_detect_middleware` wildcard (default `auth*`, so `auth:sanctum` and friends count).
 * Shared by the security layer and the implicit-401 synthesis so both key off one signal.
 *
 * The pattern is matched against every spelling of each middleware ({@see AuthMiddlewareNames}), not
 * only the string the route carried. A wildcard is written in one vocabulary — the default `auth*` in
 * the alias one — and a route naming the same middleware by class is the same route, so matching the
 * raw string alone made a configured pattern mean different things for `auth:web` and for
 * `Authenticate::using('web')`. That is not a shortfall in an error: it publishes an authenticated route
 * as public, with no 401 and no security requirement, and a generated client then omits the credential.
 */
final class AuthMiddlewareDetector
{
    public static function matches(RouteContext $context): bool
    {
        $pattern = $context->document->authMiddleware;
        if ($pattern === null || $pattern === '') {
            return false;
        }

        foreach ($context->route->middleware as $middleware) {
            foreach (AuthMiddlewareNames::spellings($middleware) as $spelling) {
                if (fnmatch($pattern, $spelling)) {
                    return true;
                }
            }
        }

        return false;
    }
}
