<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\PaginatedResponseBody;
use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;
use Docuccino\Laravel\Integrations\Support\PaginatorPageParameter;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/**
 * The request half of a paginated resource collection: off the same trace that
 * {@see PaginatedResourceResponsesExtension} rewraps the body with, the query parameter that paginator
 * kind actually reads. Without it the document tells a consumer they are on page 3 of 12 and never
 * tells them how to ask for page 4.
 *
 * Runs last in the phase so it sees what every other parameter producer contributed: a chain the
 * Query-Builder or json-api-paginate integration already documented keeps that one parameter, and so
 * does a key the author pinned themselves. Writes at the integration layer, so a later docblock,
 * overlay or config still overrides it field by field.
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final class PaginatedResourceParametersExtension implements OperationExtension
{
    /**
     * Where each of Laravel's own terminals takes the key's name —
     * `paginate($perPage, $columns, $pageName)`, `cursorPaginate($perPage, $columns, $cursorName)`.
     *
     * @var array<string, string>
     */
    private const NAME_ARGUMENT = [
        'paginate' => 'pageName',
        'simplePaginate' => 'pageName',
        'cursorPaginate' => 'cursorName',
    ];

    /** The name argument's position in all three signatures. */
    private const NAME_POSITION = 2;

    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if (PaginatedResponseBody::resourceCollectionReturn($context) === null) {
            return;
        }

        $visitor = new PaginationTerminalVisitor(PaginationTerminalVisitor::terminalsFor($context));
        $context->trace($visitor);

        if (! $visitor->paginates || $visitor->kind === null) {
            return;
        }

        $spec = $this->pageParameter($visitor, $visitor->kind);
        if ($spec === null || $this->alreadyStated($operation, $spec, $visitor->kind)) {
            return;
        }

        $spec->applyTo(
            $operation->parameter('query', $spec->name),
            Contribution::integration('api-resources', $context->actionSource()),
        );
    }

    /**
     * True when this operation already states a page selector — under the name this one would use, or
     * under the framework default some other producer would have used. Either way a second key can only
     * contradict the first, and a document that names two ways to page is worse than one that names one.
     */
    private function alreadyStated(OperationDraft $operation, QueryParameterSpec $spec, string $kind): bool
    {
        return $operation->hasParameter('query', $spec->name)
            || $operation->hasParameter('query', PaginatorPageParameter::for($kind)->name);
    }

    /**
     * The page selector for the terminal that was reached, or null where the call site renamed the key
     * to something that would not fold — a guessed `page` there names a key the endpoint does not read.
     * Only Laravel's own terminals take the argument; a custom one forwards to `paginate($perPage)` and
     * so keeps the default name.
     */
    private function pageParameter(PaginationTerminalVisitor $visitor, string $kind): ?QueryParameterSpec
    {
        $argument = self::NAME_ARGUMENT[$visitor->terminal ?? ''] ?? null;
        if ($argument === null) {
            return PaginatorPageParameter::for($kind);
        }

        $written = array_key_exists($argument, $visitor->outermostArgs)
            || array_key_exists(self::NAME_POSITION, $visitor->outermostArgs);
        if (! $written) {
            return PaginatorPageParameter::for($kind);
        }

        $name = $visitor->stringArg($argument) ?? $visitor->stringArg(self::NAME_POSITION);

        return $name === null ? null : PaginatorPageParameter::for($kind, $name);
    }
}
