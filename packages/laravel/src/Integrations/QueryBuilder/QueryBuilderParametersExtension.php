<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Patch\Contribution;

/**
 * Documents a `spatie/laravel-query-builder` list endpoint (design §Phase 4 — Query Builder). It
 * traces the action ({@see RouteContext::trace()}, so the walk's dependency files join the fragment
 * cache key) with a {@see QueryBuilderTraceVisitor} that recovers the subject model, the allow-lists
 * (with internal column names + chained `->default()`/`->nullable()` modifiers + leading comments) and
 * pagination through ANY chain depth (the two-deep `ListQueryBuilder::for()` helper pattern). Exact
 * filters whose recovered column carries a model cast are then enriched with the cast's typed schema —
 * an enum's backing values (through the shared enum machinery, so `#[CaseDescription]` prose lands as
 * `x-enumDescriptions`) or a native cast type — before the facts are expressed as query parameters
 * under the document's {@see RepresentationPolicy} and the package's own parameter names. Un-foldable
 * entries degrade to warning diagnostics naming the exact expression — never a silent drop. Writes at
 * the integration layer, so docblocks/attributes still override.
 */
final class QueryBuilderParametersExtension implements OperationExtension
{
    public function __construct(
        private readonly QueryBuilderConfig $config = new QueryBuilderConfig,
        private readonly QueryBuilderParameters $builder = new QueryBuilderParameters,
        private readonly FilterColumnResolver $columns = new FilterColumnResolver,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $visitor = new QueryBuilderTraceVisitor(customTerminals: $this->customTerminals($context));
        $context->trace($visitor);

        $facts = $visitor->facts;
        if ($facts->isEmpty()) {
            $this->reportUnresolved($facts, $context);

            return;
        }

        $this->enrichFilters($facts, $context);

        $contribution = Contribution::integration('query-builder', $context->actionSource());

        foreach ($this->builder->build($facts, $context->representation(), $this->config) as $spec) {
            $spec->applyTo($operation->parameter('query', $spec->name), $contribution);
        }

        $this->reportUnresolved($facts, $context);
        $this->reportDefaultConfig($context);
    }

    /**
     * Enrich each exact filter with the subject model's cast for its column. The model's reflected
     * shape ({@see TypeEngine::classMetadata()} dependency files) and any enum-cast file join the
     * fragment-cache key (design §10), so editing the model or the enum invalidates the warm fragment.
     */
    private function enrichFilters(QueryBuilderFacts $facts, RouteContext $context): void
    {
        if ($facts->subjectModel === null) {
            return;
        }

        $context->recordDependencyFiles(
            $context->engine->classMetadata(new ClassRef($facts->subjectModel))->dependencyFiles,
        );

        $facts->filters = array_map(
            fn (QbEntry $filter): QbEntry => $filter->kind === 'exact'
                ? $this->enrichExact($filter, $facts->subjectModel, $context)
                : $filter,
            $facts->filters,
        );
    }

    private function enrichExact(QbEntry $filter, string $model, RouteContext $context): QbEntry
    {
        $column = $this->columns->resolve($model, $filter->column());

        if ($column->isEnum() && $column->enum !== null) {
            $context->recordDependencyFiles($column->dependencyFiles);
            $schema = $context->converter()->convert(new EnumT($column->enum, EnumReflection::names($column->enum)));

            return $filter->withColumn($schema, enumTyped: true);
        }

        if ($column->isScalar() && $column->scalarSchema !== null) {
            return $filter->withColumn($column->scalarSchema, enumTyped: false);
        }

        return $filter;
    }

    private function reportUnresolved(QueryBuilderFacts $facts, RouteContext $context): void
    {
        foreach ($facts->unresolved as $expression) {
            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Warning,
                code: 'query-builder.unresolved-entry',
                message: sprintf('Could not statically resolve a Query Builder allow-list entry (%s); it is omitted from the docs.', $expression),
                routeSignature: $context->route->signature(),
                help: 'Use a literal value or a factory call (e.g. AllowedFilter::exact(\'status\')) so it can be recovered.',
            ));
        }
    }

    private function reportDefaultConfig(RouteContext $context): void
    {
        if ($this->config->recovered) {
            return;
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'query-builder.default-config',
            message: 'The query-builder config was not readable; documented parameter names use the package defaults (filter/sort/include/fields).',
            routeSignature: $context->route->signature(),
            help: 'Publish the config (php artisan vendor:publish --tag=query-builder-config) so custom parameter names are reflected in the docs.',
        ));
    }

    /**
     * @return list<string>
     */
    private function customTerminals(RouteContext $context): array
    {
        $terminals = $context->document->integration('query_builder')['pagination_terminals'] ?? null;

        return is_array($terminals) ? array_values(array_filter($terminals, 'is_string')) : [];
    }
}
