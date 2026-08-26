<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use DateTimeImmutable;
use DateTimeZone;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Support\DateWireFormat;

/**
 * `date_format:Y-m-d` → a string schema whose `format` is `date` (date-only patterns) or
 * `date-time` (patterns carrying a time token, per {@see DateWireFormat}), with the raw PHP format
 * preserved in the description so a reader keeps the exact contract. The app states the accepted wire
 * format outright here, so this is the most specific source there is and nothing displaces it.
 *
 * The wire format is the one thing the schema cannot carry, so the example is proposed here rather
 * than derived from `format` — which would document an ISO value a `d/m/Y` endpoint rejects. A fixed
 * instant in UTC, so the same pattern always renders the same bytes.
 */
final class DateFormatRuleTransformer implements RuleTransformer
{
    private const SAMPLE_INSTANT = '2024-01-01 00:00:00';

    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['date_format'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $field->setType('string');

        $pattern = $rule->parameter() ?? '';
        $field->set('format', DateWireFormat::oas($pattern));

        if ($pattern === '') {
            return;
        }

        if (! $field->has('description')) {
            $field->set('description', sprintf('Expected format: %s', $pattern));
        }

        $field->proposeExample(
            (new DateTimeImmutable(self::SAMPLE_INSTANT, new DateTimeZone('UTC')))->format($pattern),
        );
    }
}
