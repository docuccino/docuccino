<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * The number a set of JSON Schema bounds admits nearest a caller's starting point, shared by everything
 * that has to produce a value those bounds accept: the validated field's synthesized example, the
 * collection exporter's request body, and the fill an error example puts where a member went unread. One
 * ladder, so they can never disagree about what a `minimum: 5` looks like.
 *
 * A bound is unlike a `pattern`: it CONSTRAINS a value and it also names one. `minimum: 5` is satisfied
 * by 5, so there is always a legal value to reach for, where no constant satisfies an arbitrary regex.
 * That is the whole reason these five keywords are read and that one is not.
 *
 * Every answer is a constant or arithmetic on the keywords themselves — no clock, no locale, nothing from
 * another field — so the same bounds always produce the same bytes.
 *
 * `null` means the bounds admit NO number at all: bounds that cross, or a `multipleOf` with no multiple
 * between them. It never means "the value is null". Each caller decides what to do with that, because
 * only the caller knows whether it has somewhere to put the fact.
 */
final class BoundedNumber
{
    /** The five keywords that bound a number, in the order the ladder reads them. */
    private const array BOUNDS = ['minimum', 'maximum', 'multipleOf', 'exclusiveMinimum', 'exclusiveMaximum'];

    /**
     * Whether the keywords bound the number at all. Asked separately from {@see nearest()} because a
     * caller may owe an example only where something was pinned — `type: integer` already tells a reader
     * everything the seed would.
     *
     * @param  array<array-key, mixed>  $keywords
     */
    public static function stated(array $keywords): bool
    {
        foreach (self::BOUNDS as $bound) {
            if (self::bound($keywords, $bound) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value the bounds admit nearest `$seed`: raised to the floor, dropped to the ceiling, then raised
     * to the next multiple of a `multipleOf`. Wrapped, so `null` is unmistakably "no number validates".
     *
     * An exclusive bound is read as the nearest value it ADMITS, which is where the two types part: on an
     * `integer` that is exactly `floor(x) + 1`, and on a `number` it is `x + 1` — legal rather than
     * tightest, because the tightest is `x` plus an epsilon no deterministic table can name. An integer's
     * inclusive bounds round INWARD for the same reason, so a fractional one names the integer it really
     * admits.
     *
     * A `multipleOf` is applied LAST and then re-checked twice over: a step is the one keyword that can
     * move a value back out of the range the bounds put it in, and a fractional one rarely has an exactly
     * representable multiple to move it onto.
     *
     * @param  array<array-key, mixed>  $keywords
     * @return array{int|float}|null null where the bounds admit no number
     */
    public static function nearest(array $keywords, int|float $seed, bool $integer): ?array
    {
        $lower = self::bound($keywords, 'minimum');
        $upper = self::bound($keywords, 'maximum');
        $step = self::bound($keywords, 'multipleOf');

        $exclusiveLower = self::bound($keywords, 'exclusiveMinimum');
        if ($exclusiveLower !== null) {
            $admitted = $integer ? (int) floor($exclusiveLower) + 1 : $exclusiveLower + 1;
            $lower = max($lower ?? $admitted, $admitted);
        }

        $exclusiveUpper = self::bound($keywords, 'exclusiveMaximum');
        if ($exclusiveUpper !== null) {
            $admitted = $integer ? (int) ceil($exclusiveUpper) - 1 : $exclusiveUpper - 1;
            $upper = min($upper ?? $admitted, $admitted);
        }

        if ($integer) {
            $lower = $lower === null ? null : (int) ceil($lower);
            $upper = $upper === null ? null : (int) floor($upper);
        }

        $value = $seed;
        if ($lower !== null && $value < $lower) {
            $value = $lower;
        }
        if ($upper !== null && $value > $upper) {
            $value = $upper;
        }

        if ($step !== null && $step > 0) {
            $value = ceil($value / $step) * $step;

            // A fractional step rarely has an exactly representable multiple — 0.1 three times over is
            // 0.30000000000000004 in every IEEE double — and `multipleOf` is checked by exact division,
            // so the product has to be put back through it. Anything the step does not divide is a value
            // its own schema rejects, and there is no rounding of it that isn't a different guess.
            $quotient = (float) ($value / $step);
            if ($quotient !== floor($quotient)) {
                return null;
            }
        }

        if (($lower !== null && $value < $lower) || ($upper !== null && $value > $upper)) {
            return null;
        }

        // A step can also land off the integers entirely, and rounding to one would leave a value the
        // step itself rejects.
        if ($integer && is_float($value) && $value !== floor($value)) {
            return null;
        }

        // A whole float publishes as an integer literal, which validates against `number` just the same
        // and keeps the bytes the shortest true form.
        if (is_float($value) && $value === floor($value) && abs($value) < (float) PHP_INT_MAX) {
            $value = (int) $value;
        }

        return [$integer ? (int) $value : $value];
    }

    /**
     * One bound's value, or null where the keywords state none. A draft-04 document spells
     * `exclusiveMinimum` as a BOOLEAN modifying `minimum`, which names no value and is read as unstated —
     * this ladder answers 2020-12, the dialect the UIR is written in.
     *
     * @param  array<array-key, mixed>  $keywords
     */
    private static function bound(array $keywords, string $name): int|float|null
    {
        $value = $keywords[$name] ?? null;

        return is_int($value) || is_float($value) ? $value : null;
    }
}
