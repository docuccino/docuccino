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
 * list. `{}` and `{"0":"a","1":"b"}` are that set; those stay objects — {@see EmptyObject} for the
 * empty one, which is the write side's standing spelling for `{}` ({@see CanonicalJsonSerializer}).
 * Every other object, `{"1":"a","2":"b"}` and `{"201":{}}` included, is a plain array that writes back
 * as the object it was, so keeping it one would buy nothing and cost the callers that legitimately
 * hold a numerically-keyed MAP — a `responses` keyed by status code, most of all.
 *
 * Keeping that set apart is not cosmetic and nothing downstream can put it back: a JSON Schema
 * validator takes `{}` for `type: object` and refuses `[]`, and one PHP array is both. So every reader
 * that pulls JSON INTO a document comes through here — an authored `@example` literal, an
 * `#[Example(file:)]`, a recorded response body, a cached operation fragment — or a warm build
 * publishes something a cold one did not.
 *
 * @internal
 */
final class JsonValue
{
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

            if (! array_is_list($members)) {
                return $members;
            }

            return $members === [] ? EmptyObject::get() : (object) $members;
        }

        return is_array($value) ? array_map(self::normalize(...), $value) : $value;
    }
}
