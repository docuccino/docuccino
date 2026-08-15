<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `additional_properties` — a recovered `array<string, V>` property: a JSON *object* whose values all
 * share a schema. No Laravel rule says that (`array` is the only word its vocabulary has for every array
 * shape), so the recovering integration states the value schema outright, exactly as `#[RuleSchema]`
 * states `format`/`description`/`example` through {@see AnnotationRuleTransformer} rather than writing
 * keywords behind the chain. The parameter is that schema as JSON, since rule parameters are strings.
 *
 * It sets `type: object` unconditionally: it is only ever produced from a recovered map type, which is
 * strictly more precise than the `array` rule it lands beside.
 */
final class AdditionalPropertiesRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['additional_properties'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $json = $rule->parameter();
        if ($json === null) {
            return;
        }

        $value = json_decode($json, true);
        if (! is_array($value)) {
            return;
        }

        $field->setType('object');
        $field->set('additionalProperties', $value);
    }
}
