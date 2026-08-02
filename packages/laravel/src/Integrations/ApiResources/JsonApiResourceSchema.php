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
 * Maps a Laravel 13 first-party JSON:API resource
 * (`Illuminate\Http\Resources\JsonApi\JsonApiResource`, guarded by `class_exists`) to a JSON:API
 * document schema. The resource-object members come from analysing the corresponding methods:
 * `toAttributes` → `attributes`, `toRelationships` → `relationships`, `toLinks` → `links`,
 * `toMeta` → `meta` ({@see ToArrayObject}); `id`/`type` are always present strings (`toId`/`toType`).
 *
 * Runs ahead of {@see JsonResourceSchema} (a JSON:API resource IS a `JsonResource`), so it wins the
 * chain for these types. The `include`/`fields[type]` query params are added by
 * {@see JsonApiParametersExtension}. Component hoisting — including the self-reference cycle-break a
 * resource that relates to its own type needs — is owned by {@see ComponentHoist}.
 */
#[ExtensionOrder(priority: Priorities::FIRST)]
final class JsonApiResourceSchema implements TypeToSchema
{
    public function __construct(
        private readonly ToArrayObject $toArray = new ToArrayObject,
        private readonly ComponentHoist $hoist = new ComponentHoist,
    ) {}

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT && ResourceReflector::isJsonApiResource($type->fqcn);
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT) {
            return null;
        }

        return $this->hoist->hoist($context, $type->fqcn, function () use ($type, $context): array {
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

            return [
                'type' => 'object',
                'properties' => ['data' => $data],
                'required' => ['data'],
            ];
        });
    }
}
