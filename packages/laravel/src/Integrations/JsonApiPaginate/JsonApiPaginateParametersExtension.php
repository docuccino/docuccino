<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\JsonApiPaginate;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;

/**
 * Documents a `spatie/laravel-json-api-paginate` list endpoint (Phase 5c). It traces the action with
 * a {@see JsonApiPaginateTraceVisitor} (so the walk's dependency files join the fragment-cache key),
 * and when the chain reaches `jsonPaginate()` contributes the JSON:API `page[number]`/`page[size]`
 * (or `page[cursor]`/`page[size]`) query parameters, respecting the package's configured names and
 * sizes. The response envelope is unchanged: `jsonPaginate()` returns a standard paginator whose
 * `{data, links, meta}` shape the existing paginator response mapping already emits. Writes at the
 * integration layer, so docblocks/attributes still override.
 */
final class JsonApiPaginateParametersExtension implements OperationExtension
{
    public function __construct(
        private readonly JsonApiPaginateConfig $config = new JsonApiPaginateConfig,
        private readonly JsonApiPaginateParameters $builder = new JsonApiPaginateParameters,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $visitor = new JsonApiPaginateTraceVisitor(methodName: $this->config->methodName);
        $context->trace($visitor);

        if (! $visitor->facts->paginates) {
            return;
        }

        $contribution = Contribution::integration('json-api-paginate', $context->actionSource());

        foreach ($this->builder->build($this->config, $visitor->facts) as $spec) {
            $spec->applyTo($operation->parameter('query', $spec->name), $contribution);
        }

        if (! $this->config->recovered) {
            $this->reportDefaultConfig($context);
        }
    }

    private function reportDefaultConfig(RouteContext $context): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'json-api-paginate.default-config',
            message: 'The json-api-paginate config was not readable; documented pagination parameters use the package defaults (page[number]/page[size]).',
            routeSignature: $context->route->signature(),
            help: 'Publish the config (php artisan vendor:publish --tag=json-api-paginate) so custom parameter names are reflected in the docs.',
        ));
    }
}
