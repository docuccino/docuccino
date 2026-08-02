<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\ValidationRulesToSchema;

/**
 * The default {@see ValidationRulesToSchema}: builds a request object schema by driving each
 * field's rules through the {@see RuleTransformer} chain (first `supports()` wins). Rules are
 * applied in a fixed effect order (presence/type before constraints before cross-field) regardless
 * of author order, so `['max:100', 'string']` and `['string', 'max:100']` yield identical schemas.
 * A rule no transformer handles becomes an info diagnostic and leaves the field permissive.
 */
final readonly class DefaultValidationRulesToSchema implements ValidationRulesToSchema
{
    /**
     * Effect-order rank per rule name; unlisted (custom) rules run in the middle band.
     *
     * @var array<string, int>
     */
    private const ORDER = [
        'required' => 0, 'present' => 0, 'filled' => 0, 'sometimes' => 0, 'nullable' => 0,
        'string' => 10, 'integer' => 10, 'int' => 10, 'numeric' => 10, 'boolean' => 10,
        'bool' => 10, 'array' => 10, 'email' => 10, 'uuid' => 10, 'ulid' => 10, 'url' => 10,
        'ip' => 10, 'date' => 10, 'date_format' => 10,
        'file' => 15, 'image' => 15,
        'in' => 20, 'enum' => 20, 'exists' => 20, 'unique' => 20,
        'regex' => 25,
        'min' => 30, 'max' => 30, 'between' => 30, 'size' => 30,
        'confirmed' => 40,
    ];

    /**
     * @param  list<RuleTransformer>  $transformers
     */
    public function __construct(
        private array $transformers = [],
    ) {}

    public static function withDefaults(): self
    {
        return new self(BuiltInRuleTransformers::all());
    }

    public function convert(RuleSet $rules, SchemaContext $context): ValidationSchema
    {
        $builder = new RequestSchemaBuilder;
        $diagnostics = [];

        foreach ($rules->fields as $path => $fieldRules) {
            $field = $builder->field($path);
            foreach ($this->ordered($fieldRules) as $rule) {
                $diagnostic = $this->applyRule($rule, $field, $context, $path);
                if ($diagnostic !== null) {
                    $diagnostics[] = $diagnostic;
                }
            }
        }

        if (! $builder->hasFields()) {
            return new ValidationSchema([], 'application/json', $diagnostics);
        }

        $schema = $builder->build($context->representation());
        $mediaType = $builder->isMultipart() ? 'multipart/form-data' : 'application/json';

        return new ValidationSchema($schema, $mediaType, $diagnostics);
    }

    private function applyRule(ValidationRule $rule, ValidationField $field, SchemaContext $context, string $path): ?Diagnostic
    {
        foreach ($this->transformers as $transformer) {
            if ($transformer->supports($rule)) {
                $transformer->apply($rule, $field, $context);

                return null;
            }
        }

        return new Diagnostic(
            severity: Severity::Info,
            code: 'validation.rule-unhandled',
            message: sprintf('No transformer handled validation rule "%s" on field "%s"; the property stays permissive.', $rule->name, $path),
            help: 'Register a RuleTransformer for this rule to document it.',
        );
    }

    /**
     * @param  list<ValidationRule>  $rules
     * @return list<ValidationRule>
     */
    private function ordered(array $rules): array
    {
        $indexed = [];
        foreach ($rules as $position => $rule) {
            $indexed[] = ['rule' => $rule, 'rank' => self::ORDER[$rule->name] ?? 22, 'position' => $position];
        }

        usort($indexed, static fn (array $a, array $b): int => [$a['rank'], $a['position']] <=> [$b['rank'], $b['position']]);

        return array_map(static fn (array $entry): ValidationRule => $entry['rule'], $indexed);
    }
}
