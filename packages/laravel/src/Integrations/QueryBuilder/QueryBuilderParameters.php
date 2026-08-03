<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/**
 * Turns recovered {@see QueryBuilderFacts} into query-parameter specs under a {@see
 * RepresentationPolicy} (design §Representation policies): the semantic facts are policy-independent,
 * this class is the only place the *expression* is decided — bracketed `filter[status]` params vs a
 * single `filter` deep-object, comma-string `sort`/`include` vs exploded arrays, `fields[type]`
 * sparse-fieldset params, and pagination (`page`/`per_page`, or `cursor`/`per_page`). Pure and
 * deterministic so both representation styles are dataset-testable without a pipeline.
 */
final class QueryBuilderParameters
{
    private const DEFAULT_PER_PAGE = 15;

    /**
     * Filter kind → human description fragment.
     *
     * @var array<string, string>
     */
    private const FILTER_DESCRIPTIONS = [
        'default' => 'Partial-match filter',
        'partial' => 'Partial-match filter',
        'exact' => 'Exact-match filter',
        'beginsWithStrict' => 'Begins-with filter',
        'endsWithStrict' => 'Ends-with filter',
        'scope' => 'Query-scope filter',
        'callback' => 'Custom filter',
        'custom' => 'Custom filter',
        'operator' => 'Operator filter',
        'trashed' => 'Soft-delete (trashed) filter',
        'belongsTo' => 'Relationship filter',
    ];

    /**
     * @return list<QueryParameterSpec>
     */
    public function build(QueryBuilderFacts $facts, RepresentationPolicy $policy): array
    {
        return [
            ...$this->filterParameters($facts, $policy),
            ...$this->sortParameters($facts, $policy),
            ...$this->includeParameters($facts, $policy),
            ...$this->fieldParameters($facts, $policy),
            ...$this->paginationParameters($facts),
        ];
    }

    /**
     * @return list<QueryParameterSpec>
     */
    private function filterParameters(QueryBuilderFacts $facts, RepresentationPolicy $policy): array
    {
        if ($facts->filters === []) {
            return [];
        }

        if ($policy->filtersDeepObject()) {
            $properties = [];
            foreach ($facts->filters as $filter) {
                $properties[$filter->name] = ['type' => 'string', 'description' => self::filterDescription($filter->kind)];
            }

            return [new QueryParameterSpec(
                name: 'filter',
                schema: ['type' => 'object', 'properties' => $properties],
                description: 'Filter the result set.',
                style: 'deepObject',
                explode: true,
            )];
        }

        $specs = [];
        foreach ($facts->filters as $filter) {
            $specs[] = new QueryParameterSpec(
                name: sprintf('filter[%s]', $filter->name),
                schema: ['type' => 'string'],
                description: self::filterDescription($filter->kind),
            );
        }

        return $specs;
    }

    /**
     * @return list<QueryParameterSpec>
     */
    private function sortParameters(QueryBuilderFacts $facts, RepresentationPolicy $policy): array
    {
        if ($facts->sorts === []) {
            return [];
        }

        // The `-name` descending convention: each allowed sort admits an ascending and a `-`-prefixed
        // descending form.
        $values = [];
        foreach ($facts->sorts as $sort) {
            $values[] = $sort->name;
            $values[] = '-'.$sort->name;
        }

        $names = array_map(static fn (QbEntry $s): string => $s->name, $facts->sorts);
        $description = sprintf('Sort by: %s (prefix `-` for descending).', implode(', ', $names));

        if ($policy->listsAsArray()) {
            $schema = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $values]];
            if ($facts->defaultSorts !== []) {
                $schema['default'] = $facts->defaultSorts;
            }

            return [new QueryParameterSpec('sort', $schema, $description, style: 'form', explode: false)];
        }

        $schema = ['type' => 'string'];
        if ($facts->defaultSorts !== []) {
            $schema['default'] = implode(',', $facts->defaultSorts);
        }

        return [new QueryParameterSpec('sort', $schema, $description)];
    }

    /**
     * @return list<QueryParameterSpec>
     */
    private function includeParameters(QueryBuilderFacts $facts, RepresentationPolicy $policy): array
    {
        if ($facts->includes === []) {
            return [];
        }

        $names = array_map(static fn (QbEntry $i): string => $i->name, $facts->includes);
        $description = sprintf('Include related resources: %s.', implode(', ', $names));

        if ($policy->listsAsArray()) {
            return [new QueryParameterSpec(
                'include',
                ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $names]],
                $description,
                style: 'form',
                explode: false,
            )];
        }

        return [new QueryParameterSpec('include', ['type' => 'string'], $description)];
    }

    /**
     * @return list<QueryParameterSpec>
     */
    private function fieldParameters(QueryBuilderFacts $facts, RepresentationPolicy $policy): array
    {
        if ($facts->fields === []) {
            return [];
        }

        // Group `type.field` paths by their type prefix (a bare field groups under the empty prefix).
        $byType = [];
        foreach ($facts->fields as $field) {
            $sep = strpos($field->name, '.');
            $type = $sep === false ? '' : substr($field->name, 0, $sep);
            $column = $sep === false ? $field->name : substr($field->name, $sep + 1);
            $byType[$type][] = $column;
        }

        if ($policy->filtersDeepObject()) {
            $properties = [];
            foreach ($byType as $type => $columns) {
                $key = $type === '' ? '_' : $type;
                $properties[$key] = ['type' => 'string', 'description' => sprintf('Comma-separated fields: %s.', implode(', ', $columns))];
            }

            return [new QueryParameterSpec(
                name: 'fields',
                schema: ['type' => 'object', 'properties' => $properties],
                description: 'Request a sparse fieldset.',
                style: 'deepObject',
                explode: true,
            )];
        }

        $specs = [];
        foreach ($byType as $type => $columns) {
            $specs[] = new QueryParameterSpec(
                name: $type === '' ? 'fields' : sprintf('fields[%s]', $type),
                schema: ['type' => 'string'],
                description: sprintf('Comma-separated fields: %s.', implode(', ', $columns)),
            );
        }

        return $specs;
    }

    /**
     * @return list<QueryParameterSpec>
     */
    private function paginationParameters(QueryBuilderFacts $facts): array
    {
        if (! $facts->paginates) {
            return [];
        }

        $perPage = new QueryParameterSpec(
            'per_page',
            ['type' => 'integer', 'default' => $facts->perPage ?? self::DEFAULT_PER_PAGE, 'minimum' => 1],
            'Items per page.',
        );

        if ($facts->paginationKind === 'cursor') {
            return [
                new QueryParameterSpec('cursor', ['type' => 'string'], 'Opaque cursor for the next/previous page.'),
                $perPage,
            ];
        }

        return [
            new QueryParameterSpec('page', ['type' => 'integer', 'default' => 1, 'minimum' => 1], 'Page number.'),
            $perPage,
        ];
    }

    private static function filterDescription(string $kind): string
    {
        return self::FILTER_DESCRIPTIONS[$kind] ?? 'Filter';
    }
}
