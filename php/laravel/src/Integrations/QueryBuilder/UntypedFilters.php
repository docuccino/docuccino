<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteNotes;

/**
 * The filters this route recovered that the Query Builder integration itself could not type — a
 * `callback`/`custom` whose value is user code's to decide ({@see QueryBuilderParameters::typesNothing()}).
 * Written where the parameters are published and read back at the end of the same build by
 * {@see QueryBuilderUntypedFilterExtension}, which is the first point that can see whether anything ELSE
 * typed them.
 *
 * A {@see RouteContext::notes()} channel rather than a call between the two, because the parameter phase
 * and the finalize phase are different passes over one route. It carries no collector — nothing aggregates
 * it across routes — and rides the operation fragment, so a warm hit reports what a cold build reported.
 */
final class UntypedFilters
{
    /** The {@see RouteNotes} channel; one key, valued by filter name. */
    public const string CHANNEL = 'query-builder.untyped-filter';

    /** The single key under {@see CHANNEL}; the filter names are the values. */
    public const string KEY = 'filter';

    public static function record(RouteContext $context, string $filter): void
    {
        $context->notes()->record(self::CHANNEL, self::KEY, $filter);
    }

    /**
     * The filters recorded on this route, name-sorted by {@see RouteNotes::all()} — so what is reported
     * is a function of which filters were found and never of the order the chain declared them.
     *
     * @return list<string>
     */
    public static function recorded(RouteContext $context): array
    {
        return $context->notes()->all()[self::CHANNEL][self::KEY] ?? [];
    }
}
