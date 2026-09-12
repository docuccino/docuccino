<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteNotes;

/**
 * The filters this route recovered that the Query Builder integration could not type
 * ({@see QueryBuilderParameters::typesNothing()}), each against the query parameter it was PUBLISHED
 * under — so the report {@see QueryBuilderUntypedFilterExtension} makes at the end of the build is
 * addressed at the node the parameters pass wrote, and never at one re-derived from the package config.
 *
 * A {@see RouteContext::notes()} channel because the two are separate passes over one route; it carries
 * no collector and rides the operation fragment, so a warm hit reports what a cold build reported.
 */
final class UntypedFilters
{
    private const string CHANNEL = 'query-builder.untyped-filter';

    /**
     * @param  string  $parameter  the query parameter $filter's value was published under
     */
    public static function record(RouteContext $context, string $parameter, string $filter): void
    {
        $context->notes()->record(self::CHANNEL, $parameter, $filter);
    }

    /**
     * Query parameter ⇒ the filters published under it, sorted by {@see RouteNotes::all()}, so what is
     * reported never depends on the order the chain declared them.
     *
     * @return array<string, list<string>>
     */
    public static function recorded(RouteContext $context): array
    {
        return $context->notes()->all()[self::CHANNEL] ?? [];
    }
}
