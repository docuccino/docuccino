<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\SchemaDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;

/**
 * Reports a filter the application's own code handles ({@see UntypedFilters}) that the document ends up
 * publishing with no type at all, since a parameter claiming nothing becomes an untyped value at every
 * call site of a generated client.
 *
 * A pass of its own, at `Finalize` and `LAST`, because the claim is about the OUTCOME while the
 * integration that recovers the filter writes at the integration rung — a validation rule, a docblock and
 * an attribute all land on the same parameter behind it, so the kind alone would report filters a later
 * layer typed, the very attribute this help asks for included. The condition is therefore the parameter
 * draft as it finally stands, which also keeps it quiet about a filter `#[IgnoreParam]` dropped. It
 * publishes nothing; the document is already whatever the producers made it.
 *
 * An overlay is the one layer past this one — it applies to the assembled document — so a type written
 * there leaves the report standing, and `diagnostics.accept` is the answer to that.
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class QueryBuilderUntypedFilterExtension implements OperationExtension
{
    public function __construct(
        private readonly QueryBuilderConfig $config = new QueryBuilderConfig,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Finalize;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        foreach (UntypedFilters::recorded($context) as $filter) {
            $schema = $this->publishedSchema($operation, $context, $filter);

            if ($schema === null || ! $schema->saysNothingAboutTheInstance()) {
                continue;
            }

            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'query-builder.untyped-filter',
                message: sprintf('Filter "%s" is handled by your own code and nothing types its value, so it is documented with no type at all.', $filter),
                routeSignature: $context->route->signature(),
                help: sprintf('Add #[QueryParameter(type: \'string\')] to the filter class, or to the action, to give "%s" a documented type.', $filter),
            ));
        }
    }

    /**
     * The schema the document publishes for one filter, or null where the operation carries no such node
     * at all. Addressed through {@see QueryBuilderParameters::filterTarget()} — the same reading the
     * parameters were published under — so a deepObject representation is checked at its property and a
     * bracketed one at its own parameter.
     */
    private function publishedSchema(OperationDraft $operation, RouteContext $context, string $filter): ?SchemaDraft
    {
        [$name, $property] = QueryBuilderParameters::filterTarget($filter, $context->representation(), $this->config);

        if (! $operation->hasParameter('query', $name)) {
            return null;
        }

        $schema = $operation->parameter('query', $name)->schema();

        if ($property === '') {
            return $schema;
        }

        return $schema->hasProperty($property) ? $schema->property($property) : null;
    }
}
