<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Presence rules: `required` marks the field required; `nullable` allows a null value; `sometimes`
 * (validate-only-if-present) leaves it optional. `present`/`filled` are treated as `required`.
 */
final class PresenceRuleTransformer implements RuleTransformer
{
    private const NAMES = ['required', 'nullable', 'sometimes', 'present', 'filled'];

    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, self::NAMES, true);
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        match ($rule->name) {
            'required', 'present', 'filled' => $field->markRequired(),
            'nullable' => $field->markNullable(),
            default => $field->markOptional(),
        };
    }
}
