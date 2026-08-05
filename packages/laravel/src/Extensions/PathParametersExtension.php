<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;

/**
 * Adds a path parameter for every `{param}` in the route template (design §Route-model binding).
 * A parameter bound to a model is typed from the model's ROUTE KEY — uuid/ulid/int with the matching
 * format (Laravel's default `id` route key), resolved through {@see EloquentModelReflector::keySchemaFor()}
 * so a `{model}` segment matches the model's real key rather than a hardcoded integer; an unbound
 * segment is a required string. Attribute `#[PathParameter]` refines these later through the higher
 * attribute layer.
 *
 * When the route allows trashed bindings (`->withTrashed()`), each bound parameter is flagged: a note
 * is appended to its description and a stable `x-docuccino.facts.routeBinding.withTrashed` semantic
 * fact is recorded, so consumers know a soft-deleted record resolves here.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class PathParametersExtension implements OperationExtension
{
    private const TRASHED_NOTE = 'Resolves soft-deleted (trashed) records as well as active ones.';

    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        foreach ($context->pathParameters as $name) {
            $parameter = $operation->parameter('path', $name);

            $isBound = isset($context->routeBindings[$name]);
            $contribution = $isBound
                ? Contribution::inference($context->actionSource())
                : Contribution::fallback();

            $parameter->setRequired(! in_array($name, $context->optionalPathParameters, true), $contribution);

            if ($isBound) {
                foreach (EloquentModelReflector::keySchemaFor($context->routeBindings[$name]) as $keyword => $value) {
                    $parameter->schema()->set((string) $keyword, $value, $contribution);
                }
            } else {
                $parameter->schema()->set('type', 'string', $contribution);
            }

            if ($isBound && $context->allowsTrashedBindings) {
                $parameter->setDescription(self::TRASHED_NOTE, $contribution);
                $parameter->setDocuccinoFact('routeBinding', ['withTrashed' => true]);
            }
        }
    }
}
