<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Laravel\Integrations\Eloquent\CastSchema;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;

/**
 * Resolves the typed schema a subject model pins for an exact-filter column — its cast, or the
 * primary-key schema when the column IS the key — reusing the Eloquent integration's recovery
 * ({@see EloquentModelReflector} reads `$casts` and the key facts by reflection — never booting the
 * model — and {@see CastSchema} maps a native cast to a schema fragment) and the shared
 * {@see EnumReflection} machinery. Precedence mirrors {@see EloquentModelReflector::columnSchemaFor()}
 * so a filter and a path parameter can't document the same column differently. Pure reflection: no
 * PHPStan, no engine, so it runs equally in-process (the parameters extension) and out-of-process
 * (the real-engine fixture proof).
 *
 * Only the subject model's own columns are typed; a relation-path column (`posts.title`) or an
 * unresolvable model degrades to {@see FilterColumn::none()} (the filter stays a plain string).
 */
final class FilterColumnResolver
{
    public function __construct(
        private readonly EloquentModelReflector $reflector = new EloquentModelReflector,
    ) {}

    /**
     * The typed column shape for `$column` on `$model`, or {@see FilterColumn::none()} when the model
     * is unresolvable, the column is a dotted relation path, or nothing on the model types it.
     */
    public function resolve(string $model, string $column): FilterColumn
    {
        if (str_contains($column, '.') || ! EloquentModelReflector::isModel($model)) {
            return FilterColumn::none();
        }

        return $this->ownColumn($model, $column) ?? FilterColumn::none();
    }

    /**
     * The shape the model's own declarations pin for `$column`: a uuid/ulid key format first, then the
     * column's cast, then the plain key schema. Null only when the column has no cast and is not the
     * key — the one case something else (a foreign-key hop, one day) could still answer.
     */
    private function ownColumn(string $model, string $column): ?FilterColumn
    {
        $facts = $this->reflector->facts($model);
        $isKey = $column === $facts['keyName'];

        // HasUuids/HasUlids fix the key's format outright, beating a stale cast — mirrors
        // EloquentModelReflector::columnSchemaFor().
        if ($isKey && isset($facts['keySchema']['format'])) {
            return FilterColumn::scalar($facts['keySchema']);
        }

        $cast = $facts['casts'][$column] ?? null;
        if ($cast !== null) {
            if (CastSchema::isEnum($cast)) {
                $enum = explode(':', $cast, 2)[0];
                $file = EnumReflection::file($enum);

                return FilterColumn::enum($enum, $file !== null ? [$file] : []);
            }

            $scalar = CastSchema::forCast($cast);
            if ($scalar !== null) {
                return FilterColumn::scalar($scalar);
            }

            // A custom caster CastSchema does not recognise: the key still has its declared key type;
            // any other column's wire form belongs to the caster, so it stays a plain string.
            return $isKey ? FilterColumn::scalar($facts['keySchema']) : FilterColumn::none();
        }

        return $isKey ? FilterColumn::scalar($facts['keySchema']) : null;
    }
}
