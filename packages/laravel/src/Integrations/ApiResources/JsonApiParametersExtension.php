<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Patch\Contribution;

/**
 * Adds the JSON:API query parameters Laravel's `JsonApiRequest` resolves — `include` (compound
 * documents) and `fields[TYPE]` (sparse fieldsets) — to any operation whose action returns a
 * first-party JSON:API resource or collection ({@see ResourceReflector::involvesJsonApi()}). Guarded
 * (with {@see JsonApiResourceSchema}) behind `class_exists`, so it never registers on older Laravel.
 */
final class JsonApiParametersExtension implements OperationExtension
{
    private const JSON_RESPONSE = 'Illuminate\\Http\\JsonResponse';

    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if (! $this->returnsJsonApi($context)) {
            return;
        }

        $contribution = Contribution::integration('api-resources', $context->actionSource());

        $include = $operation->parameter('query', 'include');
        $include->setDescription('Comma-separated list of relationships to include as compound-document data.', $contribution);
        $include->setRequired(false, $contribution);
        $include->schema()->set('type', 'string', $contribution);

        // Sparse fieldsets: fields[TYPE]=a,b — a deepObject of comma-separated strings keyed by type.
        $fields = $operation->parameter('query', 'fields');
        $fields->setDescription('Sparse fieldsets per resource type (fields[TYPE]=field1,field2).', $contribution);
        $fields->setRequired(false, $contribution);
        $fields->set('style', 'deepObject', $contribution);
        $fields->set('explode', true, $contribution);
        $fields->schema()->set('type', 'object', $contribution);
        $fields->schema()->set('additionalProperties', ['type' => 'string'], $contribution);
    }

    private function returnsJsonApi(RouteContext $context): bool
    {
        foreach ($context->analysis()->returns as $return) {
            if (ResourceReflector::involvesJsonApi($this->unwrap($return->type))) {
                return true;
            }
        }

        return false;
    }

    /** Unwrap a `JsonResponse<payload>` to its payload type; other types pass through. */
    private function unwrap(DType $type): DType
    {
        if ($type instanceof ClassT && $type->fqcn === self::JSON_RESPONSE) {
            return $type->typeArgs[0] ?? $type;
        }

        return $type;
    }
}
