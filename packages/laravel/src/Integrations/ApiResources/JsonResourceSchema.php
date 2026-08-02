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
use Docuccino\Core\Support\Fqcn;
use Docuccino\Laravel\Integrations\Support\SchemaIdentity;

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
    /**
     * @var array<string, string> FQCN mid-expansion → reserved component name (self-reference break)
     */
    private array $expanding = [];

    public function __construct(private readonly ToArrayObject $toArray = new ToArrayObject) {}

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

        return $this->resource($type->fqcn, $context);
    }

    private function resource(string $fqcn, SchemaContext $context): SchemaResult
    {
        if (isset($this->expanding[$fqcn])) {
            return new SchemaResult(['$ref' => '#/components/schemas/'.$this->expanding[$fqcn]], 0.9);
        }

        $schemaId = SchemaIdentity::id($fqcn) ?? $fqcn;
        $name = $context->reserveComponentName(SchemaIdentity::name($fqcn) ?? Fqcn::short($fqcn), $schemaId);

        $this->expanding[$fqcn] = $name;
        $object = $this->toArray->analyze($fqcn, 'toArray', $context);
        unset($this->expanding[$fqcn]);

        if ($object === null) {
            return new SchemaResult(['type' => 'object'], 0.4);
        }

        return new SchemaResult($context->reference($name, $object, $schemaId), 0.9);
    }
}
