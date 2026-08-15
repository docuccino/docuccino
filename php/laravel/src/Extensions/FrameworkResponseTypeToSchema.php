<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Support\FrameworkClasses;

/**
 * Stops a framework response object being documented as a body. It sits ahead of core's
 * `ClassTypeToSchema`, which is correctly framework-agnostic and would otherwise reflect
 * `Illuminate\Http\RedirectResponse` into a component of `original`/`exception`/`headers` — the
 * response object's PHP internals, which no client ever receives.
 *
 * The refusal is an open `{}` at low confidence, not a component: it says "a body of a shape this
 * build could not recover", which is the truth, wherever the type turns up — a bare return, a member
 * of a `JsonResponse|RedirectResponse` union, a property of a class being expanded. Knowing WHICH
 * framework classes count is Laravel vocabulary, so the guard lives here rather than in core
 * ({@see FrameworkClasses::isResponse()} owns the list).
 *
 * The top-level story — a redirect's 3xx, a bare `JsonResponse`'s honest 200 — belongs to
 * {@see InferredResponsesExtension}, which has the route to hang a status and a diagnostic on.
 *
 * `EARLY` is what puts it ahead of the core chain: mappers at equal priority sort by FQCN, and
 * `Docuccino\Core\…\ClassTypeToSchema` sorts before anything of the adapter's.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class FrameworkResponseTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof ClassT && FrameworkClasses::isResponse($type->fqcn);
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $this->supports($type)) {
            return null;
        }

        return new SchemaResult([], 0.1);
    }
}
