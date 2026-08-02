<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

/**
 * Entry point for the Passport integration (design §Phase 4). The service provider spreads
 * {@see extensions()} into the default set only when Passport is installed (`class_exists` guard),
 * so docuccino/laravel never hard-requires it.
 */
final class PassportIntegration
{
    public const PASSPORT = 'Laravel\\Passport\\Passport';

    public static function installed(): bool
    {
        return class_exists(self::PASSPORT);
    }

    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            PassportSecurityExtension::class,
        ];
    }
}
