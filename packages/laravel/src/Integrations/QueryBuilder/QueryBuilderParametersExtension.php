<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ParameterDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;

/**
 * Documents a `spatie/laravel-query-builder` list endpoint (design §Phase 4 — Query Builder). It
 * traces the action ({@see RouteContext::trace()}, so the walk's dependency files join the fragment
 * cache key) with a {@see QueryBuilderTraceVisitor} that recovers the allow-lists and pagination
 * through ANY chain depth (the two-deep `ListQueryBuilder::for()` helper pattern), then expresses
 * them as query parameters under the document's {@see RepresentationPolicy}. Un-foldable entries
 * degrade to warning diagnostics naming the exact expression — never a silent drop. Writes at the
 * integration layer, so docblocks/attributes still override.
 */
final class QueryBuilderParametersExtension implements OperationExtension
{
    public function __construct(
        private readonly QueryBuilderParameters $builder = new QueryBuilderParameters,
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

        $contribution = Contribution::integration('query-builder', $context->actionSource());

        foreach ($this->builder->build($facts, $context->representation()) as $spec) {
            $parameter = $operation->parameter('query', $spec->name);
            $parameter->setRequired(false, $contribution);
            $this->applySpec($parameter, $spec, $contribution);
        }

        $this->reportUnresolved($facts, $context);
    }

    private function applySpec(ParameterDraft $parameter, QueryParameterSpec $spec, Contribution $contribution): void
    {
        if ($spec->description !== null) {
            $parameter->setDescription($spec->description, $contribution);
        }
        if ($spec->style !== null) {
            $parameter->set('style', $spec->style, $contribution);
        }
        if ($spec->explode !== null) {
            $parameter->set('explode', $spec->explode, $contribution);
        }

        foreach ($spec->schema as $keyword => $value) {
            $parameter->schema()->set((string) $keyword, $value, $contribution);
        }
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

    /**
     * @return list<string>
     */
    private function customTerminals(RouteContext $context): array
    {
        $terminals = $context->document->integration('query_builder')['pagination_terminals'] ?? null;

        return is_array($terminals) ? array_values(array_filter($terminals, 'is_string')) : [];
    }
}
