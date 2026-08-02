<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Support\Fqcn;
use Docuccino\Laravel\Integrations\Support\PaginationEnvelope;

/**
 * Maps a `spatie/laravel-data` Data class (and its collections) to an OAS schema, superseding the
 * core class mapper for Data types (it runs earlier in the chain). A single Data class hoists to a
 * reusable component — named by `#[SchemaName]` (else the short class name) and pinned by
 * `#[SchemaId]` (else the FQCN) for diff identity — whose properties come from the engine's
 * {@see ClassMetadata} refined by the reflected spatie presentation facts:
 *
 * - `#[Hidden]` (spatie's or ours, property- or class-level) drops the property.
 * - `#[MapOutputName]`/`#[MapName]` renames the output key.
 * - an `Optional`/`Lazy` marker in the property type makes it non-required (and the marker is stripped
 *   from the rendered type).
 * - a nested Data property recurses through the chain back into this mapper (self-reference is
 *   cycle-broken via the reserved component name, mirroring the core class mapper).
 *
 * A `DataCollection` renders as an array of its item schema; the paginated variants
 * (`PaginatedDataCollection`/`CursorPaginatedDataCollection`) render the shared paginator envelope.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class DataSchema implements TypeToSchema
{
    /**
     * @var array<string, string> FQCN mid-expansion → its reserved component name (cycle break)
     */
    private array $expanding = [];

    public function __construct(private readonly DataClassReflector $reflector = new DataClassReflector) {}

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT
            && (DataClassReflector::isData($type->fqcn) || DataClassReflector::isDataCollection($type->fqcn));
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT) {
            return null;
        }

        if (DataClassReflector::isDataCollection($type->fqcn)) {
            return $this->collection($type, $context);
        }

        return $this->object($type, $context);
    }

    private function object(ClassT $type, SchemaContext $context): SchemaResult
    {
        $fqcn = $type->fqcn;

        if (isset($this->expanding[$fqcn])) {
            return new SchemaResult(['$ref' => '#/components/schemas/'.$this->expanding[$fqcn]], 0.9);
        }

        $facts = $this->reflector->classFacts($fqcn);
        $metadata = $context->engine()->classMetadata(new ClassRef($fqcn));

        if ($metadata->properties === []) {
            return new SchemaResult(['type' => 'object'], 0.4);
        }

        $schemaId = $facts['schemaId'] ?? $fqcn;
        $name = $context->reserveComponentName($facts['schemaName'] ?? Fqcn::short($fqcn), $schemaId);
        $this->expanding[$fqcn] = $name;

        $properties = [];
        $required = [];
        foreach ($metadata->properties as $property) {
            if (in_array($property->name, $facts['hidden'], true) || $this->reflector->isPropertyHidden($fqcn, $property->name)) {
                continue;
            }

            $clean = self::stripMarkers($property->type);
            $schema = $context->convert($clean);
            if ($property->summary !== null) {
                $schema['description'] = $property->summary;
            }
            if ($property->example !== null) {
                $schema['example'] = $property->example;
            }

            $key = $this->reflector->outputName($fqcn, $property->name);
            $properties[$key] = $schema;

            if (! $this->reflector->isPropertyOptional($fqcn, $property->name) && ! self::isNullable($clean)) {
                $required[] = $key;
            }
        }

        unset($this->expanding[$fqcn]);

        $object = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $object['required'] = $required;
        }

        return new SchemaResult($context->reference($name, $object, $schemaId), 0.9);
    }

    private function collection(ClassT $type, SchemaContext $context): SchemaResult
    {
        $item = $type->typeArgs[0] ?? null;
        $items = $item !== null ? $context->convert($item) : [];

        $schema = match ($this->reflector->collectionKind($type->fqcn)) {
            'length' => PaginationEnvelope::length($items),
            'cursor' => PaginationEnvelope::cursor($items),
            default => ['type' => 'array', 'items' => $items],
        };

        return new SchemaResult($schema, 0.9);
    }

    /** Drop spatie `Optional`/`Lazy` markers from a union so only the real type is rendered. */
    private static function stripMarkers(DType $type): DType
    {
        if (! $type instanceof UnionT) {
            return $type;
        }

        $members = array_values(array_filter(
            $type->members,
            static fn (DType $member): bool => ! (
                $member instanceof ClassT
                && (is_a($member->fqcn, DataClassReflector::OPTIONAL, true) || is_a($member->fqcn, DataClassReflector::LAZY, true))
            ),
        ));

        return $members === [] ? $type : UnionT::of($members);
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
}
