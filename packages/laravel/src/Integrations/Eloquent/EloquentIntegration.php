<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

/**
 * The entry point for the Eloquent model schema integration. Always on — illuminate/database ships
 * with every Laravel app — contributing the {@see ModelSchema} type mapper.
 */
final class EloquentIntegration
{
    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [ModelSchema::class];
    }
}
