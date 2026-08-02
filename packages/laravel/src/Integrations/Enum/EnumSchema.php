<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Enum;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Laravel\Integrations\Support\EnumReflection;

/**
 * A reflection-rich enum → schema mapper that supersedes the core case-names-only mapper (it runs
 * earlier in the chain). It documents a backed enum by its backing values (an integer schema for an
 * int-backed enum), attaches `#[CaseDescription]` prose as `x-enumDescriptions`, and honours the
 * `enums.naming` policy (`x-enumNames`/`x-enum-varnames`, off by default). A pure enum still lists
 * its case names, so it never regresses the core behaviour.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class EnumSchema implements TypeToSchema
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

        // The enum's declaring file is a fragment-cache dependency: adding/removing a case changes
        // this schema (design §10). Recorded even when reflection later falls back to DType cases.
        $file = EnumReflection::file($type->fqcn);
        if ($file !== null) {
            $context->dependsOn($file);
        }

        $values = EnumReflection::values($type->fqcn);
        if ($values === []) {
            // The engine could not reflect the enum (e.g. it is not autoloadable here); fall back to
            // the case names the DType carries.
            $values = $type->cases;
        }

        if ($values === []) {
            return new SchemaResult(['type' => 'string'], 0.5);
        }

        $allInt = $values === array_filter($values, 'is_int');

        $schema = [
            'type' => $allInt ? 'integer' : 'string',
            'enum' => $allInt ? $values : array_map(strval(...), $values),
        ];

        $descriptions = EnumReflection::descriptions($type->fqcn);
        if ($descriptions !== []) {
            $schema['x-enumDescriptions'] = $descriptions;
        }

        // Codegen name hints (design §Representation policies): the case names, emitted alongside —
        // never replacing — the value-bearing `enum` member. Default `none` emits nothing.
        $naming = $context->representation()->enumNaming;
        if (($naming === 'x-enumNames' || $naming === 'x-enum-varnames') && $type->cases !== []) {
            $schema[$naming] = $type->cases;
        }

        return new SchemaResult($schema, 0.95);
    }
}
