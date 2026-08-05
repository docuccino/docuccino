<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

/**
 * Extracts the OAuth scopes a route requires from its Passport scope middleware (design §Phase 4 —
 * Passport per-operation scopes). Passport registers `scope`/`CheckForAnyScope` (ANY-of) and
 * `scopes`/`CheckScopes` (ALL-of); both take a comma-separated scope list and both ship a
 * `::using()` helper that renders the middleware as its class FQCN. All-of scopes and any-of scopes
 * are kept apart in a {@see ScopeRequirements} so the security requirement can model each correctly.
 * Pure so the middleware map is dataset-testable.
 */
final class ScopeMiddlewareParser
{
    /**
     * All-of prefixes (`scopes:` short alias + `CheckScopes::using()` FQCN).
     *
     * @var list<string>
     */
    private const ALL_OF = ['scopes:', 'Laravel\\Passport\\Http\\Middleware\\CheckScopes:'];

    /**
     * Any-of prefixes (`scope:` short alias + `CheckForAnyScope::using()` FQCN).
     *
     * @var list<string>
     */
    private const ANY_OF = ['scope:', 'Laravel\\Passport\\Http\\Middleware\\CheckForAnyScope:'];

    /**
     * @param  list<string>  $middleware
     */
    public function parse(array $middleware): ScopeRequirements
    {
        $allOf = [];
        $anyOf = [];

        foreach ($middleware as $entry) {
            foreach ($this->scopesFor($entry, self::ALL_OF) as $scope) {
                if ($scope !== '' && ! in_array($scope, $allOf, true)) {
                    $allOf[] = $scope;
                }
            }
            foreach ($this->scopesFor($entry, self::ANY_OF) as $scope) {
                if ($scope !== '' && ! in_array($scope, $anyOf, true)) {
                    $anyOf[] = $scope;
                }
            }
        }

        return new ScopeRequirements($allOf, $anyOf);
    }

    /**
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    private function scopesFor(string $middleware, array $prefixes): array
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($middleware, $prefix)) {
                return array_map('trim', explode(',', substr($middleware, strlen($prefix))));
            }
        }

        return [];
    }
}
