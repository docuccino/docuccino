<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

use Docuccino\Core\Lint\CredentialShapes;
use Docuccino\Core\Lint\SensitiveFieldLint;
use Docuccino\Core\Lint\SensitiveFieldLintOptions;
use stdClass;

/**
 * Takes the secrets out of a recorded response body, and — separately — says whether any are still in
 * one.
 *
 * It is the same knowledge {@see SensitiveFieldLint} publishes on: {@see SensitiveFieldLintOptions}
 * for member names that mean a secret, {@see CredentialShapes} for strings only a real credential
 * plausibly matches. The lint reports; this replaces, because a recording is captured from a live
 * request and a document is a thing people publish.
 *
 * Two rules, both narrow on purpose. Only STRINGS are replaced, so `token_count: 5` keeps its type and
 * the example goes on satisfying its own schema. And a sensitive member name taints everything beneath
 * it, so `credentials: {id, value}` loses both halves rather than only the half whose own name gave it
 * away.
 *
 * @internal
 */
final readonly class ExampleRedaction
{
    /** What a removed value is replaced with. Recognisable in a diff, and obviously not a credential. */
    public const string PLACEHOLDER = '[redacted]';

    /** As deep as a response body is walked; past that a value is left exactly as it is. */
    private const int MAX_DEPTH = 64;

    public function __construct(
        private SensitiveFieldLintOptions $options = new SensitiveFieldLintOptions,
    ) {}

    /**
     * The body with every credential replaced, and the JSON pointers that were.
     *
     * @return array{0: mixed, 1: list<string>}
     */
    public function apply(mixed $body): array
    {
        $pointers = [];
        $redacted = $this->walk($body, '', false, $pointers, true, 0);
        sort($pointers);

        return [$redacted, $pointers];
    }

    /**
     * The pointers of every value in an already-committed body that still looks like a credential —
     * a hand edit, or a heuristics table that learned something after the recording was made.
     *
     * @return list<string>
     */
    public function findings(mixed $body): array
    {
        $pointers = [];
        $this->walk($body, '', false, $pointers, false, 0);
        sort($pointers);

        return $pointers;
    }

    /**
     * One walk for both jobs: $replace says whether to hand back a cleaned value or only to collect.
     *
     * @param  list<string>  $pointers
     */
    private function walk(mixed $value, string $pointer, bool $tainted, array &$pointers, bool $replace, int $depth): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return $value;
        }

        // An object stays an object: `{}` is how a body says "a map with nothing in it", and a list
        // member's index is never a member NAME, so it can never taint what sits under it.
        $map = $value instanceof stdClass;
        if ($map) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            $list = ! $map && array_is_list($value);
            $out = [];
            foreach ($value as $key => $child) {
                $name = (string) $key;
                $childPointer = $pointer.'/'.self::escape($name);
                $childTainted = $tainted || (! $list && $this->sensitive($name));
                $out[$name] = $this->walk($child, $childPointer, $childTainted, $pointers, $replace, $depth + 1);
            }

            return $map ? (object) $out : ($list ? array_values($out) : $out);
        }

        if (! is_string($value) || $value === '' || $value === self::PLACEHOLDER) {
            return $value;
        }

        if (! $tainted && CredentialShapes::label($value) === null) {
            return $value;
        }

        if (in_array($pointer, $this->options->allow, true)) {
            return $value;
        }

        $pointers[] = $pointer;

        return $replace ? self::PLACEHOLDER : $value;
    }

    /** Whether a member name means "a secret lives here", safelist honoured. */
    private function sensitive(string $name): bool
    {
        return $this->options->match($name) !== null && ! in_array($name, $this->options->allow, true);
    }

    /** RFC 6901 escaping, so a member called `a/b` addresses one place rather than two. */
    private static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
