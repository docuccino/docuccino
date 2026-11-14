<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `not_in:a,b,c` → `not: {enum: […]}` — the mirror of {@see ChoiceRuleTransformer}'s `in`, excluding a
 * value set instead of restricting to one. Also covers spatie's `#[NotIn]`, which recovers the same rule.
 *
 * Unlike `in`, it never types the field: every value outside the set passes, whatever its type, so a type
 * read from the set would refuse requests the server takes. The type is the other rules' to state.
 */
final class NotInRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return $rule->name === 'not_in';
    }

    public function handledRuleNames(): array
    {
        return ['not_in'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $values = $rule->parameters;
        if ($values === []) {
            return;
        }

        $allNumeric = true;
        foreach ($values as $value) {
            if (! is_numeric($value) || str_contains($value, '.')) {
                $allNumeric = false;
                break;
            }
        }

        $field->set('not', ['enum' => $allNumeric ? array_map('intval', $values) : $values]);
    }
}
