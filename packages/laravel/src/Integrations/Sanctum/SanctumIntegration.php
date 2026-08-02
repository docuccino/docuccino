<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

/**
 * Entry point for the Sanctum integration (design §Phase 4). The service provider spreads
 * {@see extensions()} into the default set only when Sanctum is installed (`class_exists` guard),
 * so docuccino/laravel never hard-requires it.
 */
final class SanctumIntegration
{
    public const SANCTUM = 'Laravel\\Sanctum\\Sanctum';

    public static function installed(): bool
    {
        return class_exists(self::SANCTUM);
    }

    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            SanctumSecurityExtension::class,
        ];
    }
}
