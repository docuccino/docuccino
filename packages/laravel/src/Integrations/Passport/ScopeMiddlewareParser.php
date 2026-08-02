<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

/**
 * Extracts the OAuth scopes a route requires from its Passport `scope:`/`scopes:` middleware
 * (design §Phase 4 — Passport per-operation scopes). Passport registers `scope` (any-of) and
 * `scopes` (all-of); both take a comma-separated scope list. The union across all matching
 * middleware, de-duplicated in declaration order, becomes the security-requirement scope array.
 * Pure so the middleware map is dataset-testable.
 */
final class ScopeMiddlewareParser
{
    /**
     * @param  list<string>  $middleware
     * @return list<string>
     */
    public function scopes(array $middleware): array
    {
        $scopes = [];
        foreach ($middleware as $entry) {
            foreach ($this->scopesIn($entry) as $scope) {
                if ($scope !== '' && ! in_array($scope, $scopes, true)) {
                    $scopes[] = $scope;
                }
            }
        }

        return $scopes;
    }

    /**
     * @return list<string>
     */
    private function scopesIn(string $middleware): array
    {
        foreach (['scope:', 'scopes:'] as $prefix) {
            if (str_starts_with($middleware, $prefix)) {
                return array_map('trim', explode(',', substr($middleware, strlen($prefix))));
            }
        }

        return [];
    }
}
