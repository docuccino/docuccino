<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Support\Fqcn;
use Docuccino\Laravel\Integrations\Support\EnumReflection;
use Docuccino\Laravel\Integrations\Support\SchemaIdentity;

/**
 * Maps an Eloquent model to an object schema (superseding the core class mapper for models). Columns
 * come from the engine's {@see ClassMetadata}; the model's own presentation
 * facts ({@see EloquentModelReflector}) refine the set:
 *
 * - `$visible` (allow-list) / `$hidden` + a class-level `#[Hidden]` list (deny-list) filter columns.
 * - `$casts` fix the schema of a column: datetime → `format: date-time`, native casts fix the type
 *   ({@see CastSchema}); an enum cast routes the column through the Enum integration path (`EnumT`).
 * - `$appends` add accessor-backed properties (optional; permissive when untyped).
 *
 * The component is named by `#[SchemaName]` (else the short class name) and pinned by `#[SchemaId]`
 * (else the FQCN); self-references are cycle-broken via the reserved name.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class ModelSchema implements TypeToSchema
{
    /**
     * @var array<string, string> FQCN mid-expansion → reserved component name (self-reference break)
     */
    private array $expanding = [];

    public function __construct(private readonly EloquentModelReflector $reflector = new EloquentModelReflector) {}

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT && EloquentModelReflector::isModel($type->fqcn);
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT) {
            return null;
        }

        $fqcn = $type->fqcn;
        if (isset($this->expanding[$fqcn])) {
            return new SchemaResult(['$ref' => '#/components/schemas/'.$this->expanding[$fqcn]], 0.9);
        }

        $facts = $this->reflector->facts($fqcn);
        $metadata = $context->engine()->classMetadata(new ClassRef($fqcn));

        $schemaId = SchemaIdentity::id($fqcn) ?? $fqcn;
        $name = $context->reserveComponentName(SchemaIdentity::name($fqcn) ?? Fqcn::short($fqcn), $schemaId);
        $this->expanding[$fqcn] = $name;

        $hidden = [...$facts['hidden'], ...$facts['classHidden']];

        $properties = [];
        $required = [];
        foreach ($metadata->properties as $property) {
            if (! self::isColumnVisible($property->name, $facts['visible'], $hidden)) {
                continue;
            }

            $schema = $this->columnSchema($property->name, $property->type, $facts['casts'], $context);
            if ($property->summary !== null) {
                $schema['description'] = $property->summary;
            }
            $properties[$property->name] = $schema;

            if (! self::isNullable($property->type)) {
                $required[] = $property->name;
            }
        }

        // Appended accessors: optional, permissive unless a cast pins the shape.
        foreach ($facts['appends'] as $append) {
            if (isset($properties[$append])) {
                continue;
            }
            $properties[$append] = $this->castSchema($append, $facts['casts'], $context) ?? [];
        }

        unset($this->expanding[$fqcn]);

        $object = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $object['required'] = $required;
        }

        return new SchemaResult($context->reference($name, $object, $schemaId), 0.9);
    }

    /**
     * The schema for a column: its cast shape when the model casts it, else its inferred type.
     *
     * @param  array<string, string>  $casts
     * @return array<string, mixed>
     */
    private function columnSchema(string $column, DType $type, array $casts, SchemaContext $context): array
    {
        return $this->castSchema($column, $casts, $context) ?? $context->convert($type);
    }

    /**
     * The schema a cast pins for a column (datetime format / native type / enum via the Enum path),
     * or null when the column has no cast this mapper recognises.
     *
     * @param  array<string, string>  $casts
     * @return array<string, mixed>|null
     */
    private function castSchema(string $column, array $casts, SchemaContext $context): ?array
    {
        $cast = $casts[$column] ?? null;
        if ($cast === null) {
            return null;
        }

        if (CastSchema::isEnum($cast)) {
            $enum = explode(':', $cast, 2)[0];

            return $context->convert(new EnumT($enum, EnumReflection::names($enum)));
        }

        return CastSchema::forCast($cast);
    }

    /**
     * @param  list<string>  $visible
     * @param  list<string>  $hidden
     */
    private static function isColumnVisible(string $column, array $visible, array $hidden): bool
    {
        // $visible is an allow-list when set; otherwise everything not in $hidden is visible.
        return $visible !== [] ? in_array($column, $visible, true) : ! in_array($column, $hidden, true);
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
