<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Statically parses recovered Laravel rule strings into {@see ValidationRule}s — never executing
 * anything. A pipe string (`'required|max:100'`) splits into tokens; a token (`'in:a,b'`) splits
 * into a name plus its parameters, read as Laravel reads them. Array-form rules feed their string items
 * through {@see token()}.
 */
final class RuleParsing
{
    /**
     * @return list<ValidationRule>
     */
    public static function tokens(string $pipe): array
    {
        $out = [];
        foreach (explode('|', $pipe) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $out[] = self::token($token);
            }
        }

        return $out;
    }

    public static function token(string $token): ValidationRule
    {
        $colon = strpos($token, ':');
        if ($colon === false) {
            return ValidationRule::of($token);
        }

        $name = substr($token, 0, $colon);

        return ValidationRule::of($name, self::parameters($name, substr($token, $colon + 1)));
    }

    /**
     * A token's parameters as Laravel's `ValidationRuleParser` reads them: a regex is one parameter, commas,
     * quotes and all, and anything else is a CSV row, so `in:"a,b",""` lists `a,b` and the empty string.
     *
     * @return list<string>
     */
    private static function parameters(string $name, string $parameters): array
    {
        if (in_array(strtolower(trim($name)), ['regex', 'not_regex', 'notregex'], true)) {
            return [trim($parameters)];
        }

        if (trim($parameters) === '') {
            return [];
        }

        return array_map(
            static fn (?string $parameter): string => trim((string) $parameter),
            str_getcsv($parameters, escape: '\\'),
        );
    }
}
