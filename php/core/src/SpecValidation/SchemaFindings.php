<?php

declare(strict_types=1);

namespace Docuccino\Core\SpecValidation;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError as OpisValidationError;
use Opis\JsonSchema\JsonPointer;
use Opis\JsonSchema\Validator;

/**
 * Turns an opis validation into {@see Finding}s, each reading as one line:
 * `<data pointer> <keyword>: <message> (schema <schema pointer>)`.
 *
 * Every schema oracle reports through this, because `isValid()` failing says only "false is not true" —
 * which names neither the position in the document nor the rule it broke.
 *
 * @internal
 */
final class SchemaFindings
{
    /**
     * Every way $instance fails the schema registered at $uri. Empty means valid.
     *
     * @return list<Finding>
     */
    public static function of(Validator $validator, mixed $instance, string $uri): array
    {
        $error = $validator->validate($instance, $uri)->error();

        if ($error === null) {
            return [];
        }

        $findings = [];

        foreach ((new ErrorFormatter)->formatKeyed(
            $error,
            static fn (OpisValidationError $e): Finding => new Finding(
                self::root(self::pointer($e->data()->fullPath())),
                $e->keyword(),
                (new ErrorFormatter)->formatErrorMessage($e),
                self::pointer($e->schema()->info()->path()),
            ),
            static fn (OpisValidationError $e): string => self::pointer($e->data()->fullPath()),
        ) as $found) {
            // One position answers one finding, or a list of them where opis reports several there.
            foreach (is_array($found) ? $found : [$found] as $finding) {
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /** The document root reads as `/`, which is what a person looks for; JSON Pointer spells it `''`. */
    private static function root(string $pointer): string
    {
        return $pointer === '' ? '/' : $pointer;
    }

    /**
     * A JSON pointer a person can read. opis percent-encodes tokens on the way out, which turns the two
     * things a reader navigates by — `$defs` and a templated path segment — into `%24defs` and `%7Bid%7D`.
     *
     * @param  array<array-key, mixed>  $path
     */
    private static function pointer(array $path): string
    {
        return rawurldecode(JsonPointer::pathToString($path));
    }
}
