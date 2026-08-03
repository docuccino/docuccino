<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\TimacdonaldJsonApi;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\ApiResources\JsonApiParameters;

/**
 * Adds the JSON:API query parameters `timacdonald/json-api` resolves — `include` (compound documents)
 * and `fields[TYPE]` (sparse fieldsets) — to any operation whose action returns a timacdonald JSON:API
 * resource or collection ({@see TimacdonaldResourceReflector::involvesJsonApi()}), deferring the
 * actual parameter writes to the shared {@see JsonApiParameters} applier. Guarded (with the schema
 * mapper) behind `class_exists`, so it never registers when the package is absent.
 */
final class TimacdonaldJsonApiParametersExtension implements OperationExtension
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

        JsonApiParameters::apply($operation, Contribution::integration('timacdonald-json-api', $context->actionSource()));
    }

    private function returnsJsonApi(RouteContext $context): bool
    {
        foreach ($context->analysis()->returns as $return) {
            if (TimacdonaldResourceReflector::involvesJsonApi($this->unwrap($return->type))) {
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
