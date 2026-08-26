<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Support\DateWireFormat;

/**
 * `date_wire` — the wire shape a date-typed property really accepts, stated outright by the recovering
 * integration from the most specific source it has, because Laravel's rule vocabulary has no word for
 * it. Runs straight after the type rules and sets `format` UNCONDITIONALLY: a bare `date` rule accepts
 * anything non-relative `strtotime` parses, so the `format: date` {@see TypeRuleTransformer} pairs with
 * it is only its reading of intent where nothing better is known — which is why that transformer sets a
 * format only on a field that hasn't got one, and why this rule outranks what it left.
 *
 * `timestamp` is the one shape that isn't a string at all, so the coarse rule's `format` goes with the
 * type it belonged to.
 */
final class DateWireRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['date_wire'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $shape = $rule->parameter();

        if ($shape === DateWireFormat::TIMESTAMP) {
            $field->setType('integer');
            $field->remove('format');
            if (! $field->has('description')) {
                $field->set('description', 'Unix timestamp (seconds).');
            }

            return;
        }

        if ($shape !== 'date' && $shape !== 'date-time') {
            return;
        }

        $field->setType('string');
        $field->set('format', $shape);
    }
}
