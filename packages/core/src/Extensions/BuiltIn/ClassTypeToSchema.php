<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnionT;

/**
 * A named class → an object schema hoisted to `components.schemas` and referenced by `$ref`
 * (design §5 component hoisting). Properties come from {@see TypeEngine::classMetadata()}; the
 * FQCN is passed through as the component's `schemaId` hint so the assembler can pin its diff
 * identity (`#[SchemaName]`/`#[SchemaId]` overrides land in the integration layer, Phase 4).
 *
 * A class the engine cannot expand degrades to a bare `{type: object}` at low confidence. A
 * self-referential class is cycle-broken via the `expanding` guard, which returns a `$ref` to
 * the component being built rather than recursing.
 */
final class ClassTypeToSchema implements TypeToSchema
{
    /**
     * @var array<string, true> FQCNs currently mid-expansion, for cycle breaking
     */
    private array $expanding = [];

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT) {
            return null;
        }

        $fqcn = $type->fqcn;
        $name = self::shortName($fqcn);

        if (isset($this->expanding[$fqcn])) {
            return new SchemaResult(['$ref' => '#/components/schemas/'.$name], 0.9);
        }

        $metadata = $context->engine()->classMetadata(new ClassRef($fqcn));

        if ($metadata->properties === []) {
            return new SchemaResult(['type' => 'object'], 0.4);
        }

        $this->expanding[$fqcn] = true;

        $properties = [];
        $required = [];
        foreach ($metadata->properties as $property) {
            $schema = $context->convert($property->type);
            if ($property->summary !== null) {
                $schema['description'] = $property->summary;
            }
            $properties[$property->name] = $schema;
            if (! self::isNullable($property->type)) {
                $required[] = $property->name;
            }
        }

        unset($this->expanding[$fqcn]);

        $object = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $object['required'] = $required;
        }

        return new SchemaResult($context->reference($name, $object, $fqcn), 0.9);
    }

    private static function isNullable(DType $type): bool
    {
        if (! $type instanceof UnionT) {
            return false;
        }

        foreach ($type->members as $member) {
            if ($member instanceof NullT) {
                return true;
            }
        }

        return false;
    }

    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
