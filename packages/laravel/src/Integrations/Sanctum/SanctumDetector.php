<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

/**
 * Decides, from a route's gathered middleware, which Sanctum auth modes protect the operation
 * (design §Phase 4 — Sanctum auto-config). Two independent signals: the `auth:sanctum` guard (or the
 * bare `sanctum` alias) enables TOKEN mode; the stateful-frontend middleware (registered app-wide by
 * `statefulApi()`) enables STATEFUL/cookie mode. A dual-auth app exhibits both on the same route, so
 * this returns the SET of active modes rather than a single choice. Pure so the map is dataset-tested.
 */
final class SanctumDetector
{
    public const TOKEN = 'token';

    public const STATEFUL = 'stateful';

    private const STATEFUL_MIDDLEWARE = 'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful';

    /**
     * The modes the route itself supports, in a stable order (token before stateful).
     *
     * @param  list<string>  $middleware
     * @return list<string>
     */
    public function supportedModes(array $middleware): array
    {
        $modes = [];
        if ($this->hasTokenGuard($middleware)) {
            $modes[] = self::TOKEN;
        }
        if (in_array(self::STATEFUL_MIDDLEWARE, $middleware, true)) {
            $modes[] = self::STATEFUL;
        }

        return $modes;
    }

    /**
     * @param  list<string>  $middleware
     */
    private function hasTokenGuard(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            if ($entry === 'sanctum') {
                return true;
            }
            if (str_starts_with($entry, 'auth:') && in_array('sanctum', array_map('trim', explode(',', substr($entry, 5))), true)) {
                return true;
            }
        }

        return false;
    }
}
