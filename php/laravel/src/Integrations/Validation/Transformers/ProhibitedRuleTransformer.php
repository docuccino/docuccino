<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;

/**
 * The prohibition family, split by whether the field may EVER be sent.
 *
 * A bare `prohibited` never may, so the field is dropped from the rule set before the chain runs
 * ({@see RuleSetNormalizer}) — documenting it would invite exactly the value the API rejects. Reaching a
 * chain that wasn't normalised, it stays a no-op rather than inventing a keyword.
 *
 * `prohibited_if`/`prohibited_unless` reject the field only under a runtime condition, and `prohibits`
 * constrains OTHER fields entirely. Those are all legitimately sendable, so they stay documented and
 * optional, with the condition as a description note.
 */
final class ProhibitedRuleTransformer implements RuleTransformer
{
    private const NAMES = ['prohibited', 'prohibits', 'prohibited_if', 'prohibited_unless'];

    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return self::NAMES;
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $note = $this->describe($rule);
        if ($note === null) {
            return;
        }

        $existing = $field->get('description');
        $field->set('description', is_string($existing) && $existing !== '' ? $existing.' '.$note : $note);
    }

    private function describe(ValidationRule $rule): ?string
    {
        $params = $rule->parameters;
        if ($params === []) {
            return null;
        }

        $other = $params[0];
        $values = array_slice($params, 1);

        return match ($rule->name) {
            'prohibited_if' => $values === []
                ? sprintf('Must not be sent when %s is present.', $other)
                : sprintf('Must not be sent when %s is %s.', $other, implode(' or ', $values)),
            'prohibited_unless' => sprintf('Must not be sent unless %s is %s.', $other, $values === [] ? 'present' : implode(' or ', $values)),
            'prohibits' => sprintf('Sending this field requires %s to be absent.', implode(', ', $params)),
            default => null,
        };
    }
}
