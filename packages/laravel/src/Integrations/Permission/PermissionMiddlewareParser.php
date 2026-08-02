<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Permission;

/**
 * Parses a `spatie/laravel-permission` middleware string into a {@see PermissionRequirement} (design
 * §Phase 4). The three aliases — `role:`, `permission:`, `role_or_permission:` — each take a pipe-
 * separated any-of list and an optional `,guard` suffix (`permission:edit articles,web`). Anything
 * else returns null. Pure so the middleware map is dataset-testable.
 */
final class PermissionMiddlewareParser
{
    /**
     * @var list<string>
     */
    private const TYPES = ['role_or_permission', 'permission', 'role'];

    public function parse(string $middleware): ?PermissionRequirement
    {
        foreach (self::TYPES as $type) {
            if (! str_starts_with($middleware, $type.':')) {
                continue;
            }

            $parameters = substr($middleware, strlen($type) + 1);
            $parts = explode(',', $parameters, 2);
            $values = array_values(array_filter(array_map('trim', explode('|', $parts[0])), static fn (string $v): bool => $v !== ''));
            $guard = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : null;

            return $values === [] ? null : new PermissionRequirement($type, $values, $guard);
        }

        return null;
    }
}
