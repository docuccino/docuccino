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
 * Maps a Laravel 13 first-party JSON:API resource
 * (`Illuminate\Http\Resources\JsonApi\JsonApiResource`, guarded by `class_exists`) to a JSON:API
 * document schema. The resource-object members come from analysing the corresponding methods:
 * `toAttributes` → `attributes`, `toRelationships` → `relationships`, `toLinks` → `links`,
 * `toMeta` → `meta` ({@see ToArrayObject}); `id`/`type` are always present strings (`toId`/`toType`).
 *
 * Runs ahead of {@see JsonResourceSchema} (a JSON:API resource IS a `JsonResource`), so it wins the
 * chain for these types. The `include`/`fields[type]` query params are added by
 * {@see JsonApiParametersExtension}.
 */
#[ExtensionOrder(priority: Priorities::FIRST)]
final class JsonApiResourceSchema implements TypeToSchema
{
    public function __construct(private readonly ToArrayObject $toArray = new ToArrayObject) {}

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT && ResourceReflector::isJsonApiResource($type->fqcn);
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT) {
            return null;
        }

        $data = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string'],
                'type' => ['type' => 'string'],
            ],
            'required' => ['id', 'type'],
        ];

        foreach (['attributes' => 'toAttributes', 'relationships' => 'toRelationships', 'links' => 'toLinks', 'meta' => 'toMeta'] as $member => $method) {
            $object = $this->toArray->analyze($type->fqcn, $method, $context);
            if ($object !== null && ($object['properties'] ?? []) !== []) {
                $data['properties'][$member] = $object;
            }
        }

        $document = [
            'type' => 'object',
            'properties' => ['data' => $data],
            'required' => ['data'],
        ];

        $schemaId = SchemaIdentity::id($type->fqcn) ?? $type->fqcn;
        $name = SchemaIdentity::name($type->fqcn) ?? Fqcn::short($type->fqcn);

        return new SchemaResult($context->reference($name, $document, $schemaId), 0.9);
    }
}
