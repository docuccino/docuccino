<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentHoist;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\UnionT;

/**
 * Maps an Eloquent model to an object schema (superseding the core class mapper for models).
 *
 * The column universe is a union, most-authoritative first (design — see
 * docs/design/inference-embedding.md §"Eloquent column source"):
 *
 * 1. the engine's {@see ClassMetadata} — a real model declares no PHP column properties, so this is
 *    almost entirely its class-level `@property`/`@property-read` docblock tags (typed, high
 *    confidence); a native public property, where one exists, also lands here;
 * 2. floor sources reflected off the model — a `$casts` key IS a column (typed via its cast), a
 *    `$dates` entry is a date-time column, and a `$fillable`-only name is a permissive column at
 *    lowered confidence.
 *
 * The model's own presentation facts ({@see EloquentModelReflector}) then refine the set:
 *
 * - `$visible` (allow-list) / `$hidden` + a class-level `#[Hidden]` list (deny-list) filter columns.
 * - `$casts` fix the schema of a column: datetime → `format: date-time`, native casts fix the type
 *   ({@see CastSchema}); an enum cast routes the column through the Enum integration path (`EnumT`).
 * - `$appends` add accessor-backed properties (optional; permissive when untyped).
 *
 * When NO source yields a column, today's behaviour is kept (an empty object plus any appends) but an
 * info diagnostic tells the author how to document columns (`@property` docblocks) — never silent.
 *
 * The component is named by `#[SchemaName]` (else the short class name) and pinned by `#[SchemaId]`
 * (else the FQCN); self-references are cycle-broken via the reserved name.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class ModelSchema implements TypeToSchema
{
    public function __construct(
        private readonly EloquentModelReflector $reflector = new EloquentModelReflector,
        private readonly ComponentHoist $hoist = new ComponentHoist,
    ) {}

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

        return $this->hoist->hoist($context, $fqcn, function () use ($fqcn, $context): array {
            $facts = $this->reflector->facts($fqcn);
            $metadata = $context->engine()->classMetadata(new ClassRef($fqcn));

            // The model's reflected shape is a fragment-cache dependency (design §10): editing the model
            // (a new column/cast, a changed $hidden list) must invalidate the warm fragment. Enum-cast
            // third files are recorded as each cast is resolved in castSchema().
            $context->dependsOn(...$metadata->dependencyFiles);

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

                if (! ($property->type instanceof UnionT && $property->type->containsNull())) {
                    $required[] = $property->name;
                }
            }

            // Floor columns: a column the engine did not surface but the model itself evidences —
            // a `$casts` key (typed by its cast), a `$dates` entry (date-time), or a `$fillable`-only
            // name (permissive, at lowered confidence). Docblock/native columns above are more
            // authoritative, so an already-present name is left untouched.
            foreach ($this->floorColumns($facts) as $column) {
                if (isset($properties[$column]) || ! self::isColumnVisible($column, $facts['visible'], $hidden)) {
                    continue;
                }

                [$schema, $isRequired] = $this->floorColumnSchema($column, $facts, $context);
                $properties[$column] = $schema;
                if ($isRequired) {
                    $required[] = $column;
                }
            }

            // No source yielded a column: keep the empty-object behaviour but tell the author how to
            // document one, so an undocumented model never renders as a silent bare object.
            if ($properties === []) {
                $context->diagnostic(new Diagnostic(
                    severity: Severity::Info,
                    code: 'eloquent.no-columns',
                    message: sprintf('Model %s exposes no documentable columns; its response is documented as a bare object.', $fqcn),
                    help: 'Add `@property` (or `@property-read`) docblock tags for the model\'s attributes — e.g. `@property int $id` — so its columns and their types are recovered.',
                ));
            }

            // Appended accessors: optional, permissive unless a cast pins the shape.
            foreach ($facts['appends'] as $append) {
                if (isset($properties[$append])) {
                    continue;
                }
                $properties[$append] = $this->castSchema($append, $facts['casts'], $context) ?? [];
            }

            $object = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $object['required'] = $required;
            }

            return $object;
        });
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
     * The floor-source column names, in deterministic priority order: `$casts` keys, then `$dates`,
     * then `$fillable`. Deduped, first occurrence wins (so a name's most-authoritative floor source
     * decides its type in {@see floorColumnSchema()}).
     *
     * @param  array{casts: array<string, string>, dates: list<string>, fillable: list<string>}  $facts
     * @return list<string>
     */
    private function floorColumns(array $facts): array
    {
        $seen = [];
        foreach ([...array_keys($facts['casts']), ...$facts['dates'], ...$facts['fillable']] as $name) {
            $seen[$name] = true;
        }

        return array_keys($seen);
    }

    /**
     * The schema (and whether it is required) for a floor column: its cast shape when cast, a
     * date-time when a `$dates` entry, else a permissive `{}` at lowered confidence for a
     * `$fillable`-only name whose type is genuinely unknown (also the case for a custom caster the
     * cast table does not recognise). Cast/date floor columns are treated as always-serialised
     * (required); an untyped permissive one is left optional, since its presence is a guess.
     *
     * @param  array{casts: array<string, string>, dates: list<string>, fillable: list<string>}  $facts
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function floorColumnSchema(string $column, array $facts, SchemaContext $context): array
    {
        $cast = $this->castSchema($column, $facts['casts'], $context);
        if ($cast !== null) {
            return [$cast, true];
        }

        if (in_array($column, $facts['dates'], true)) {
            return [['type' => 'string', 'format' => 'date-time'], true];
        }

        $context->lowerConfidence(0.6);

        return [[], false];
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

            // The backing enum is a third-file cache dependency of the model schema (design §10).
            $enumFile = EnumReflection::file($enum);
            if ($enumFile !== null) {
                $context->dependsOn($enumFile);
            }

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
}
