<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Permission;

/**
 * Entry point for the `spatie/laravel-permission` integration (design §Phase 4). The service provider
 * spreads {@see extensions()} into the default set only when the package is installed (`class_exists`
 * guard), so docuccino/laravel never hard-requires it.
 */
final class PermissionIntegration
{
    public const PROVIDER = 'Spatie\\Permission\\PermissionServiceProvider';

    public static function installed(): bool
    {
        return class_exists(self::PROVIDER);
    }

    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            PermissionExtension::class,
        ];
    }
}
