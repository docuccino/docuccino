<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * The single entry point for the `spatie/laravel-query-builder` integration (design §Phase 4). The
 * service provider spreads {@see extensions()} into the default set only when the QueryBuilder class
 * exists (`class_exists` guard, Telescope pattern), so docuccino/laravel never hard-requires the
 * package. Productionises Spike B: recovers allowedFilters/Sorts/Includes/Fields + pagination
 * through any chain depth via the trace boundary.
 */
final class QueryBuilderIntegration
{
    public const QUERY_BUILDER = 'Spatie\\QueryBuilder\\QueryBuilder';

    public static function installed(): bool
    {
        return class_exists(self::QUERY_BUILDER);
    }

    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            QueryBuilderParametersExtension::class,
        ];
    }
}
