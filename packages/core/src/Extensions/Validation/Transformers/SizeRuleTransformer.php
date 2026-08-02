<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Size rules (`min`, `max`, `between:a,b`, `size:n`), applied type-aware (Laravel's own semantics):
 * a numeric field bounds `minimum`/`maximum`, an array bounds `minItems`/`maxItems`, and any other
 * field bounds `minLength`/`maxLength`. Runs after {@see TypeRuleTransformer} so the field type is
 * known; an untyped field defaults to string-length bounds, matching Laravel's default coercion.
 */
final class SizeRuleTransformer implements RuleTransformer
{
    private const NAMES = ['min', 'max', 'between', 'size'];

    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, self::NAMES, true);
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        [$minKeyword, $maxKeyword] = $this->keywords($field->type());

        match ($rule->name) {
            'min' => $this->write($field, $minKeyword, $rule->parameter()),
            'max' => $this->write($field, $maxKeyword, $rule->parameter()),
            'size' => $this->size($field, $minKeyword, $maxKeyword, $rule->parameter()),
            default => $this->between($field, $minKeyword, $maxKeyword, $rule),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function keywords(?string $type): array
    {
        return match ($type) {
            'integer', 'number' => ['minimum', 'maximum'],
            'array' => ['minItems', 'maxItems'],
            default => ['minLength', 'maxLength'],
        };
    }

    private function between(ValidationField $field, string $minKeyword, string $maxKeyword, ValidationRule $rule): void
    {
        $this->write($field, $minKeyword, $rule->parameter(0));
        $this->write($field, $maxKeyword, $rule->parameter(1));
    }

    private function size(ValidationField $field, string $minKeyword, string $maxKeyword, ?string $value): void
    {
        $this->write($field, $minKeyword, $value);
        $this->write($field, $maxKeyword, $value);
    }

    private function write(ValidationField $field, string $keyword, ?string $value): void
    {
        if ($value === null || ! is_numeric($value)) {
            return;
        }

        $field->set($keyword, str_contains($value, '.') ? (float) $value : (int) $value);
    }
}
