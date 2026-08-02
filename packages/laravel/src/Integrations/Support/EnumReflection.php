<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Attributes\CaseDescription;
use Docuccino\Core\Inference\DType\EnumT;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionEnumUnitCase;
use Throwable;

/**
 * Reflection over a PHP enum for the integrations: the documentable case values (backing values for
 * a backed enum, case names otherwise) and any `#[CaseDescription]` prose keyed by that same value.
 * Totalising — a non-enum or reflection failure yields empty results rather than throwing.
 */
final class EnumReflection
{
    /**
     * The case values a backed enum exposes (its backing values) or, for a pure enum, its case
     * names — in declaration order.
     *
     * @return list<string|int>
     */
    public static function values(string $fqcn): array
    {
        return array_map(self::caseValue(...), self::cases($fqcn));
    }

    /**
     * The case names of an enum, in declaration order — the {@see EnumT}
     * `cases` contract (distinct from backing {@see values()}).
     *
     * @return list<string>
     */
    public static function names(string $fqcn): array
    {
        return array_map(static fn (ReflectionEnumUnitCase $case): string => $case->getName(), self::cases($fqcn));
    }

    /**
     * `#[CaseDescription]` prose keyed by the same value {@see values()} emits — so the map lines up
     * with the schema's `enum` member for `x-enumDescriptions`. Cases without the attribute are
     * omitted.
     *
     * @return array<string, string>
     */
    public static function descriptions(string $fqcn): array
    {
        $out = [];
        foreach (self::cases($fqcn) as $case) {
            $attributes = $case->getAttributes(CaseDescription::class);
            if ($attributes === []) {
                continue;
            }

            try {
                $description = $attributes[0]->newInstance()->description;
            } catch (Throwable) {
                continue;
            }

            $out[(string) self::caseValue($case)] = $description;
        }

        return $out;
    }

    /**
     * @return list<ReflectionEnumUnitCase>
     */
    private static function cases(string $fqcn): array
    {
        if (! enum_exists($fqcn)) {
            return [];
        }

        try {
            return array_values((new ReflectionEnum($fqcn))->getCases());
        } catch (Throwable) {
            return [];
        }
    }

    private static function caseValue(ReflectionEnumUnitCase $case): string|int
    {
        if ($case instanceof ReflectionEnumBackedCase) {
            $value = $case->getBackingValue();

            return is_int($value) ? $value : (string) $value;
        }

        return $case->getName();
    }
}
