<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * One link in the per-rule transformer chain a {@see ValidationRulesToSchema} drives (design §6 —
 * the Scribe `RuleTransformer` parity point). The first transformer whose {@see supports()} is true
 * handles the rule and applies its effect to the field being built; a rule no transformer supports
 * becomes an info diagnostic and leaves the field permissive.
 *
 * Transformers are pure and framework-agnostic: they read a {@see ValidationRule} (a name + string
 * parameters, recovered statically — never executed) and mutate a {@see ValidationField}. Cross-field
 * rules (`confirmed`) and media-type effects (`file`/`image`) reach siblings and the request root
 * through the field facade.
 */
interface RuleTransformer
{
    public function supports(ValidationRule $rule): bool;

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void;
}
