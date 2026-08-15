<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Makes a recovered field map coherent before the chain runs — the two facts that are only visible
 * across fields, which a per-field {@see RuleTransformer} therefore cannot see:
 *
 * - A bare `prohibited` field, and everything under it, is REMOVED. The API rejects the field outright,
 *   so documenting it as an optional property invites exactly what it refuses. The conditional forms
 *   (`prohibited_if`/`prohibited_unless`) and `prohibits` (which constrains other fields) are left alone —
 *   those fields are legitimately sendable.
 * - A field with a dotted child key (`metadata.retention.mode`) IS an object, so its `array` type rule is
 *   dropped: Laravel's `array` covers both JSON arrays and JSON objects, and the child disambiguates.
 *   Leaving both produces `{"type": "array", "properties": …}`, which no document validates against. A
 *   `*` child is the array case and keeps its rule.
 *
 * Shared by every recovery integration (FormRequest, inline validate, laravel-actions, Spatie Data)
 * alongside {@see RuleOrdering}, so every rule set reaches the chain in the same shape.
 */
final class RuleSetNormalizer
{
    /** Type rules meaning "PHP array", which a named child key resolves to an object. */
    private const ARRAY_RULES = ['array', 'list'];

    public function normalize(RuleSet $rules): RuleSet
    {
        $fields = $this->withoutProhibited($rules->fields);

        $out = [];
        foreach ($fields as $field => $fieldRules) {
            $out[$field] = $this->hasNamedChild($field, $fields)
                ? array_values(array_filter($fieldRules, static fn (ValidationRule $rule): bool => ! in_array($rule->name, self::ARRAY_RULES, true)))
                : $fieldRules;
        }

        return new RuleSet($out);
    }

    /**
     * @param  array<string, list<ValidationRule>>  $fields
     * @return array<string, list<ValidationRule>>
     */
    private function withoutProhibited(array $fields): array
    {
        $prohibited = [];
        foreach ($fields as $field => $fieldRules) {
            foreach ($fieldRules as $rule) {
                if ($rule->name === 'prohibited') {
                    $prohibited[] = $field;
                    break;
                }
            }
        }

        if ($prohibited === []) {
            return $fields;
        }

        $out = [];
        foreach ($fields as $field => $fieldRules) {
            foreach ($prohibited as $dropped) {
                if ($field === $dropped || str_starts_with($field, $dropped.'.')) {
                    continue 2;
                }
            }
            $out[$field] = $fieldRules;
        }

        return $out;
    }

    /**
     * Whether any other field path is a named (non-`*`) child of this one.
     *
     * @param  array<string, list<ValidationRule>>  $fields
     */
    private function hasNamedChild(string $field, array $fields): bool
    {
        $prefix = $field.'.';
        foreach (array_keys($fields) as $other) {
            if ($other !== $field && str_starts_with($other, $prefix) && ! str_starts_with(substr($other, strlen($prefix)), '*')) {
                return true;
            }
        }

        return false;
    }
}
