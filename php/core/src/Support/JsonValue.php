<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use JsonException;
use stdClass;

/**
 * Reading JSON into the PHP shape the rest of the pipeline works in — the ONE reader, so two callers
 * cannot come to different conclusions about the same bytes.
 *
 * An object becomes an associative array, which is what every draft, canonicaliser and emitter takes,
 * EXCEPT the objects a PHP array cannot represent: exactly the ones whose member names re-key to a
 * `0..n-1` run, since PHP re-reads `"0"` as the integer 0 and an array of `0,1,2…` writes back as a
 * list. `{}` and `{"0":"a","1":"b"}` are that set; those stay {@see stdClass}, which is the codebase's
 * standing spelling for a JSON object a PHP array cannot hold ({@see CanonicalJsonSerializer} writes
 * one as `{}`). Every other object, `{"1":"a","2":"b"}` and `{"201":{}}` included, is a plain array
 * that writes back as the object it was, so keeping it one would buy nothing and cost the callers that
 * legitimately hold a numerically-keyed MAP — a `responses` keyed by status code, most of all.
 *
 * Keeping that set apart is not cosmetic and nothing downstream can put it back: a JSON Schema
 * validator takes `{}` for `type: object` and refuses `[]`, and one PHP array is both. So every reader
 * that pulls JSON INTO a document comes through here — an authored `@example` literal, an
 * `#[Example(file:)]`, a recorded response body, a cached operation fragment, a committed artifact
 * re-read for a diff or for the viewer — or a warm build publishes something a cold one did not.
 * `JsonValueArchTest` is that rule enforced rather than asked for.
 *
 * Each such object is MINTED FRESH, and every reader that asks "did two producers write the same
 * value?" therefore has to ask it by value ({@see same()}), never with `===`: `===` on an object is
 * instance identity, so two equal `{}` would read as a disagreement. A single shared instance would
 * answer `===` and is not available — a `stdClass` subclass cannot be made immutable in PHP (a
 * by-reference property acquisition — `$o->p[] = 1`, `$o->p++`, `preg_match(…, $o->p)` — never
 * consults `__set`), so sharing one would put any caller's stray member into every `{}` in the
 * document, and `DocumentTransformer` hands drafts to code we do not own.
 *
 * @internal
 */
final class JsonValue
{
    /** How deep {@see same()} descends before it stops; the same bound, and for the same reason, as {@see Json}. */
    private const int MAX_DEPTH = 128;

    /**
     * @throws JsonException when the string is not JSON
     */
    public static function decode(string $json): mixed
    {
        return self::normalize(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * The same reading for a value the caller decoded itself — which is how a caller that has to
     * CLASSIFY the literal before publishing it gets both answers off one decode.
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $members = array_map(self::normalize(...), (array) $value);

            return array_is_list($members) ? (object) $members : $members;
        }

        return is_array($value) ? array_map(self::normalize(...), $value) : $value;
    }

    /**
     * Whether two values say the same thing: `===` in every respect EXCEPT that two `stdClass` standing
     * for the same JSON object are one value however they were minted.
     *
     * Nothing else is relaxed, deliberately. `Json::stable` is not the tool here — JSON has no way to
     * write an integer-valued float, so it answers `1` for both `1.0` and `1`, and the codebase treats
     * those as different values wherever it reads a document rather than its bytes.
     */
    public static function same(mixed $a, mixed $b, int $depth = 0): bool
    {
        if ($a === $b) {
            return true;
        }

        // A value nested deeper than any real document goes is likelier self-referential than equal, and
        // recursing into one is a stack overflow rather than an exception. "Different" is the harmless
        // answer: the patch guard's cost for it is one `overrode` entry too many, never one too few.
        if ($depth >= self::MAX_DEPTH) {
            return false;
        }

        if ($a instanceof stdClass && $b instanceof stdClass) {
            return self::same(get_object_vars($a), get_object_vars($b), $depth + 1);
        }

        if (! is_array($a) || ! is_array($b) || array_keys($a) !== array_keys($b)) {
            return false;
        }

        foreach ($a as $key => $value) {
            if (! self::same($value, $b[$key], $depth + 1)) {
                return false;
            }
        }

        return true;
    }
}
