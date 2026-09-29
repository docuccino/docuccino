<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `regex:/…/` → a string schema with the body as its ECMA-262 `pattern`, but only when the body means
 * the same thing there as it does to PHP. A modifier changing what matches (`i`, `m`, `s`, `x`), a body
 * outside the portable subset, or a regex PHP cannot compile publishes no pattern: a plain string is
 * wider than the server, and true.
 */
final class RegexRuleTransformer implements RuleTransformer
{
    /** The PCRE modifiers that change which strings match; dropping any other (`u`, `A`, `D`, `U`, …) never narrows it. */
    private const MATCH_CHANGING = ['i', 'm', 's', 'x'];

    /**
     * The subset ECMA-262 reads exactly as PCRE does, with or without either engine's `u`: printable ASCII
     * literals, escaped syntax characters, positive bracket classes, `(…)`/`(?:…)`, `|`, `^`/`$` and the
     * greedy or lazy quantifiers. Every atom matches ASCII alone, so a count means the same in bytes, code
     * points and UTF-16 units. `%s` is the class escapes, which are ASCII only while PHP's `u` is off.
     */
    private const PORTABLE = <<<'REGEX'
        /\A(?:
            (?:
                [\x20-\x23\x25-\x27\x2C\x2D\x2F-\x3E\x40-\x5A\x5F-\x7A\x7E]
              | \\[\^$\\.*+?()[\]{}|\/]
              %1$s
              | \[(?!\^)(?:[\x20-\x5A\x5E-\x7E] | \\[\^$\\.*+?()[\]{}|\/-] %1$s)+\]
              | \)
            )(?:(?:[*+?]|\{[0-9]+(?:,[0-9]*)?\})\??)?
          | \((?:\?:)?
          | [|^$]
        )*\z/x
        REGEX;

    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['regex'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        if (! $field->has('type')) {
            $field->setType('string');
        }

        $regex = implode(',', $rule->parameters);
        [$body, $modifiers] = $this->split($regex);

        $dropped = array_values(array_intersect(self::MATCH_CHANGING, str_split($modifiers)));
        if ($dropped !== []) {
            $context->diagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'validation.regex-modifier',
                message: sprintf(
                    'The regex on field "%s" carries the /%s modifier, which a JSON Schema pattern cannot state, so no pattern is published.',
                    $field->path(),
                    implode('', $dropped),
                ),
                help: 'Spell what the modifier does inside the pattern — `[a-zA-Z]` for `/i`, `[\s\S]` for a dot under `/s` — '
                    .'and the pattern is published. Without the modifier the pattern would refuse values your API accepts, '
                    .'so the field is published without one rather than wrongly.',
            ));

            return;
        }

        if (@preg_match($regex, '') !== false && $this->portable($body, str_contains($modifiers, 'u'))) {
            $field->set('pattern', $body);

            return;
        }

        $context->diagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'validation.regex-unportable',
            message: sprintf(
                'The regex on field "%s" reads differently as a JSON Schema pattern, so no pattern is published.',
                $field->path(),
            ),
            help: 'Spell it with ASCII literals, escaped syntax characters, bracket classes such as `[0-9]`, `^` and `$`, groups, '
                .'alternation and quantifiers, and it is published. Under `/u`, `\d`, `\w` and `\s` match every script, which '
                .'a pattern cannot say; `\A`, `\z`, inline flags and lookarounds are PHP-only.',
        ));
    }

    private function portable(string $body, bool $unicode): bool
    {
        return preg_match(sprintf(self::PORTABLE, $unicode ? '' : '| \\\\[dws]'), $body) === 1;
    }

    /**
     * The pattern body and the modifiers after its closing delimiter.
     *
     * @return array{string, string}
     */
    private function split(string $pattern): array
    {
        if (strlen($pattern) < 2) {
            return [$pattern, ''];
        }

        $delimiter = $pattern[0];
        $closing = match ($delimiter) {
            '(' => ')',
            '{' => '}',
            '[' => ']',
            '<' => '>',
            default => $delimiter,
        };

        $end = strrpos($pattern, $closing);
        if ($end === false || $end === 0) {
            return [$pattern, ''];
        }

        return [substr($pattern, 1, $end - 1), substr($pattern, $end + 1)];
    }
}
