<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `file`/`image` → a binary string schema and a request-wide switch to `multipart/form-data` (a
 * file field can't be JSON). `image` additionally notes the constraint in the description.
 */
final class FileRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['file', 'image'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $field->setType('string');
        $field->set('format', 'binary');
        $field->markMultipart();

        if ($rule->name === 'image' && ! $field->has('description')) {
            $field->set('description', 'An image file.');
        }
    }
}
