<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;

/**
 * An enum → a string schema enumerating its case names, with the `x-enumDescriptions` hook
 * left for the enum/`#[CaseDescription]` integration to fill (Phase 4). In Phase 3a the engine
 * hands us case names only (not backing values), so the enum lists names at slightly reduced
 * confidence.
 */
final class EnumTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof EnumT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof EnumT) {
            return null;
        }

        if ($type->cases === []) {
            return new SchemaResult(['type' => 'string'], 0.5);
        }

        return new SchemaResult(['type' => 'string', 'enum' => $type->cases], 0.9);
    }
}
