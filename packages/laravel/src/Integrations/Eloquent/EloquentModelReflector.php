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

    /** Whether an FQCN is a concrete Eloquent model (the schema mapper's trigger). */
    public static function isModel(string $fqcn): bool
    {
        return $fqcn !== self::MODEL && is_a($fqcn, self::MODEL, true);
    }

    /**
     * @return array{hidden: list<string>, visible: list<string>, appends: list<string>, casts: array<string, string>, classHidden: list<string>}
     */
    public function facts(string $fqcn): array
    {
        if (! class_exists($fqcn)) {
            return ['hidden' => [], 'visible' => [], 'appends' => [], 'casts' => [], 'classHidden' => []];
        }

        $reflection = new ReflectionClass($fqcn);
        $defaults = $reflection->getDefaultProperties();

        $classHidden = [];
        foreach ($reflection->getAttributes(DocuccinoHidden::class) as $attribute) {
            $classHidden = [...$classHidden, ...$attribute->newInstance()->properties];
        }

        return [
            'hidden' => self::stringList($defaults['hidden'] ?? []),
            'visible' => self::stringList($defaults['visible'] ?? []),
            'appends' => self::stringList($defaults['appends'] ?? []),
            'casts' => self::castMap($defaults['casts'] ?? []),
            'classHidden' => $classHidden,
        ];
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
