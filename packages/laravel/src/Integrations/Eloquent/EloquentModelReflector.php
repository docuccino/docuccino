<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Attributes\Hidden as DocuccinoHidden;
use Docuccino\Core\Inference\ClassMetadata;
use ReflectionClass;

/**
 * Reads the presentation facts an Eloquent model declares — `$visible`/`$hidden`/`$appends`, the
 * `$casts` map, and any class-level `#[Hidden]` list — via native reflection of the class's default
 * property values (never instantiating the model, so no boot side effects or DB access). These
 * refine the column set the engine's {@see ClassMetadata} supplies.
 */
final class EloquentModelReflector
{
    public const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    private const SOFT_DELETES = 'Illuminate\\Database\\Eloquent\\SoftDeletes';

    private const HAS_UUIDS = 'Illuminate\\Database\\Eloquent\\Concerns\\HasUuids';

    private const HAS_ULIDS = 'Illuminate\\Database\\Eloquent\\Concerns\\HasUlids';

    /** Whether an FQCN is a concrete Eloquent model (the schema mapper's trigger). */
    public static function isModel(string $fqcn): bool
    {
        return $fqcn !== self::MODEL && is_a($fqcn, self::MODEL, true);
    }

    /**
     * @return array{hidden: list<string>, visible: list<string>, appends: list<string>, casts: array<string, string>, classHidden: list<string>, fillable: list<string>, dates: list<string>, timestamps: bool, softDeletes: bool, keyName: string, keySchema: array<string, mixed>}
     */
    public function facts(string $fqcn): array
    {
        if (! class_exists($fqcn)) {
            return ['hidden' => [], 'visible' => [], 'appends' => [], 'casts' => [], 'classHidden' => [], 'fillable' => [], 'dates' => [], 'timestamps' => false, 'softDeletes' => false, 'keyName' => 'id', 'keySchema' => ['type' => 'integer']];
        }

        $reflection = new ReflectionClass($fqcn);
        $defaults = $reflection->getDefaultProperties();

        $classHidden = [];
        foreach ($reflection->getAttributes(DocuccinoHidden::class) as $attribute) {
            $classHidden = [...$classHidden, ...$attribute->newInstance()->properties];
        }

        $traits = self::traits($fqcn);

        return [
            'hidden' => self::stringList($defaults['hidden'] ?? []),
            'visible' => self::stringList($defaults['visible'] ?? []),
            'appends' => self::stringList($defaults['appends'] ?? []),
            'casts' => self::castMap($defaults['casts'] ?? []),
            'classHidden' => $classHidden,
            'fillable' => self::stringList($defaults['fillable'] ?? []),
            'dates' => self::stringList($defaults['dates'] ?? []),
            // Timestamps default on unless the model sets `$timestamps = false`.
            'timestamps' => ($defaults['timestamps'] ?? true) !== false,
            'softDeletes' => in_array(self::SOFT_DELETES, $traits, true),
            'keyName' => is_string($defaults['primaryKey'] ?? null) ? $defaults['primaryKey'] : 'id',
            'keySchema' => self::keySchema($defaults, $traits),
        ];
    }

    /**
     * The primary-key column schema: a `HasUuids`/`HasUlids` model keys on a string with the matching
     * format; otherwise an incrementing integer key, or a plain string for a non-incrementing string
     * key. Only ever applied to the key column, and only when a trait/keyType makes it authoritative.
     *
     * @param  array<string, mixed>  $defaults
     * @param  list<string>  $traits
     * @return array<string, mixed>
     */
    private static function keySchema(array $defaults, array $traits): array
    {
        if (in_array(self::HAS_UUIDS, $traits, true)) {
            return ['type' => 'string', 'format' => 'uuid'];
        }
        if (in_array(self::HAS_ULIDS, $traits, true)) {
            return ['type' => 'string', 'format' => 'ulid'];
        }

        $keyType = $defaults['keyType'] ?? 'int';

        return $keyType === 'string' ? ['type' => 'string'] : ['type' => 'integer'];
    }

    /**
     * Every trait used by the class and its parents (the `class_uses_recursive` equivalent), read via
     * reflection without instantiating.
     *
     * @return list<string>
     */
    private static function traits(string $fqcn): array
    {
        $traits = [];
        for ($class = $fqcn; $class !== false; $class = get_parent_class($class)) {
            foreach (class_uses($class) ?: [] as $trait) {
                $traits[$trait] = true;
            }
        }

        return array_keys($traits);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @return array<string, string>
     */
    private static function castMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $column => $cast) {
            if (is_string($column) && is_string($cast)) {
                $out[$column] = $cast;
            }
        }

        return $out;
    }
}
