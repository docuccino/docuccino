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
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Laravel\Integrations\Support\ComponentHoist;
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
    public function __construct(
        private readonly DataClassReflector $reflector = new DataClassReflector,
        private readonly ComponentHoist $hoist = new ComponentHoist,
    ) {}

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
        $facts = $this->reflector->classFacts($fqcn);
        $metadata = $context->engine()->classMetadata(new ClassRef($fqcn));

        // The Data class's reflected shape is a fragment-cache dependency (design §10): editing a
        // property type / #[Hidden] / MapName must invalidate the warm fragment.
        $context->dependsOn(...$metadata->dependencyFiles);

        // An unexpandable Data class degrades to a bare object without reserving a component name —
        // there is no body, so nothing self-references it.
        if ($metadata->properties === []) {
            return new SchemaResult(['type' => 'object'], 0.4);
        }

        return $this->hoist->hoist($context, $fqcn, function () use ($fqcn, $facts, $metadata, $context): array {
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

                if (! $this->reflector->isPropertyOptional($fqcn, $property->name) && ! ($clean instanceof UnionT && $clean->containsNull())) {
                    $required[] = $key;
                }
            }

            $object = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $object['required'] = $required;
            }

            return $object;
        }, $facts['schemaName'], $facts['schemaId']);
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

        return $type->without(static fn (DType $member): bool => $member instanceof ClassT
            && (is_a($member->fqcn, DataClassReflector::OPTIONAL, true) || is_a($member->fqcn, DataClassReflector::LAZY, true)));
    }
}
