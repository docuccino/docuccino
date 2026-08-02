<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Integrations\Support\ComponentHoist;

/**
 * Maps a Laravel API Resource to a schema (superseding the core class mapper for resource types).
 *
 * - A single `JsonResource` hoists to a component built from its analysed `toArray` shape
 *   ({@see ToArrayObject}) — `whenLoaded`/`when`/`whenNotNull`/`mergeWhen` fields become optional —
 *   named by `#[SchemaName]` (else short class name) and pinned by `#[SchemaId]` (else the FQCN).
 * - An anonymous resource collection (`Resource::collection(...)`) renders as an array of its item
 *   schema.
 *
 * JSON:API resources are handled by {@see JsonApiResourceSchema}, which runs ahead of this mapper;
 * this mapper explicitly declines them.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class JsonResourceSchema implements TypeToSchema
{
    public function __construct(
        private readonly ToArrayObject $toArray = new ToArrayObject,
        private readonly ComponentHoist $hoist = new ComponentHoist,
    ) {}

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT
            && ResourceReflector::isResource($type->fqcn)
            && ! ResourceReflector::isJsonApiResource($type->fqcn);
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT) {
            return null;
        }

        if (ResourceReflector::isAnonymousCollection($type->fqcn)) {
            $item = $type->typeArgs[0] ?? null;

            return new SchemaResult(['type' => 'array', 'items' => $item !== null ? $context->convert($item) : []], 0.9);
        }

        return $this->hoist->hoist($context, $type->fqcn, fn (): ?array => $this->toArray->analyze($type->fqcn, 'toArray', $context));
    }
}
