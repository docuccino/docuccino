<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Extensions\Validation\ValidationSchema;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;

/**
 * Exercises the Laravel rule vocabulary (the transformer set + effect-order ranking) driving the
 * core chain — the Laravel-side counterpart to the core driver's vocabulary-free unit test.
 */
function vocabularyContext(): SchemaConverter
{
    return new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);
}

/**
 * Recover rules from `field => 'pipe|string'` shorthand, order them Laravel-style, and convert.
 *
 * @param  array<string, string>  $fields
 */
function convertLaravelRules(array $fields): ValidationSchema
{
    $set = [];
    foreach ($fields as $field => $pipe) {
        $set[$field] = RuleParsing::tokens($pipe);
    }

    $ordered = (new RuleOrdering)->order(new RuleSet($set));

    return (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))->convert($ordered, vocabularyContext());
}

/**
 * Convert a single field's rules built as `[name, parameters, note?]` tuples — the low-level path
 * that lets the floor dataset drive rules (enum values, exists table args) a `pipe|string` cannot
 * express — through the SHARED ordering + core chain the real integrations use.
 *
 * @param  list<array{0: string, 1?: list<string>, 2?: string}>  $rules
 */
function convertFieldRules(array $rules): ValidationSchema
{
    $ruleObjects = array_map(
        static fn (array $r): ValidationRule => ValidationRule::of($r[0], $r[1] ?? [], $r[2] ?? null),
        $rules,
    );

    $ordered = (new RuleOrdering)->order(new RuleSet(['f' => $ruleObjects]));

    return (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))->convert($ordered, vocabularyContext());
}

/**
 * The floor list (design §Test-coverage standards): EVERY string-rule entry across ALL transformers
 * has a dataset row asserting its schema effect. Presence/cross-field/multipart effects — which
 * surface off the property schema — are asserted in the dedicated tests below, so together every
 * entry in every transformer's table is proven.
 */
it('maps every schema-producing string rule to its fragment', function (array $rules, array $expected): void {
    $property = convertFieldRules($rules)->schema['properties']['f'];
    ksort($property);
    ksort($expected);

    expect($property)->toBe($expected);
})->with([
    // TypeRuleTransformer — base type + format entries.
    'string' => [[['string']], ['type' => 'string']],
    'integer' => [[['integer']], ['type' => 'integer']],
    'int' => [[['int']], ['type' => 'integer']],
    'numeric' => [[['numeric']], ['type' => 'number']],
    'boolean' => [[['boolean']], ['type' => 'boolean']],
    'bool' => [[['bool']], ['type' => 'boolean']],
    'array' => [[['array']], ['type' => 'array']],
    'email' => [[['email']], ['format' => 'email', 'type' => 'string']],
    'uuid' => [[['uuid']], ['format' => 'uuid', 'type' => 'string']],
    'ulid' => [[['ulid']], ['format' => 'ulid', 'type' => 'string']],
    'url' => [[['url']], ['format' => 'uri', 'type' => 'string']],
    'ip' => [[['ip']], ['format' => 'ip', 'type' => 'string']],
    'date' => [[['date']], ['format' => 'date', 'type' => 'string']],

    // ChoiceRuleTransformer — string set and numeric set, plus the enum-FQCN note.
    'in (string set)' => [[['in', ['draft', 'published']]], ['enum' => ['draft', 'published'], 'type' => 'string']],
    'in (numeric set)' => [[['in', ['1', '2', '3']]], ['enum' => [1, 2, 3], 'type' => 'integer']],
    'enum (folded values + note)' => [[['enum', ['a', 'b'], 'App\\Enums\\Kind']], ['description' => 'App\\Enums\\Kind', 'enum' => ['a', 'b'], 'type' => 'string']],

    // SizeRuleTransformer — type-aware min/max/between/size.
    'min (string length)' => [[['string'], ['min', ['2']]], ['minLength' => 2, 'type' => 'string']],
    'max (string length)' => [[['string'], ['max', ['9']]], ['maxLength' => 9, 'type' => 'string']],
    'between (numeric bounds)' => [[['integer'], ['between', ['1', '5']]], ['maximum' => 5, 'minimum' => 1, 'type' => 'integer']],
    'size (array items)' => [[['array'], ['size', ['3']]], ['maxItems' => 3, 'minItems' => 3, 'type' => 'array']],

    // DateFormatRuleTransformer — date-only vs time-bearing pattern.
    'date_format (date)' => [[['date_format', ['Y-m-d']]], ['description' => 'Expected format: Y-m-d', 'format' => 'date', 'type' => 'string']],
    'date_format (date-time)' => [[['date_format', ['Y-m-d H:i:s']]], ['description' => 'Expected format: Y-m-d H:i:s', 'format' => 'date-time', 'type' => 'string']],

    // RegexRuleTransformer — delimiters stripped to a bare ECMA-262 pattern.
    'regex' => [[['regex', ['/^[a-z]+$/']]], ['pattern' => '^[a-z]+$', 'type' => 'string']],

    // ExistsRuleTransformer — a FK reference contributes a default string type only.
    'exists' => [[['exists', ['users', 'id']]], ['type' => 'string']],
    'unique' => [[['unique', ['users']]], ['type' => 'string']],

    // FileRuleTransformer — binary string schema (multipart switch asserted separately).
    'file' => [[['file']], ['format' => 'binary', 'type' => 'string']],
    'image' => [[['image']], ['description' => 'An image file.', 'format' => 'binary', 'type' => 'string']],
]);

it('applies every presence-rule entry to the required/nullable contract', function (): void {
    // required / present / filled all mark the field required.
    expect(convertFieldRules([['string'], ['required']])->schema['required'] ?? [])->toBe(['f']);
    expect(convertFieldRules([['string'], ['present']])->schema['required'] ?? [])->toBe(['f']);
    expect(convertFieldRules([['string'], ['filled']])->schema['required'] ?? [])->toBe(['f']);

    // sometimes leaves the field optional (no `required` list emitted).
    expect(convertFieldRules([['string'], ['sometimes']])->schema)->not->toHaveKey('required');

    // nullable widens the type to allow null (2020-12 default policy → `[t, null]`).
    expect(convertFieldRules([['string'], ['nullable']])->schema['properties']['f']['type'])->toBe(['string', 'null']);
});

it('documents the confirmed partner and switches file rules to multipart', function (): void {
    $confirmed = convertFieldRules([['string'], ['required'], ['confirmed']]);
    expect($confirmed->schema['properties'])->toHaveKey('f_confirmation')
        ->and($confirmed->schema['properties']['f_confirmation'])->toBe(['type' => 'string'])
        ->and($confirmed->schema['required'])->toBe(['f', 'f_confirmation']);

    expect(convertFieldRules([['file']])->mediaType)->toBe('multipart/form-data')
        ->and(convertFieldRules([['image']])->mediaType)->toBe('multipart/form-data');
});

it('applies size rules type-aware and independent of author order', function (): void {
    $direct = convertLaravelRules(['name' => 'string|min:2|max:100'])->schema;
    $reordered = convertLaravelRules(['name' => 'max:100|min:2|string'])->schema;
    $int = convertLaravelRules(['age' => 'integer|between:1,120'])->schema;

    // Same keywords/values whatever the author order; key order (a cosmetic difference the
    // canonicaliser normalises at emit) can vary among equal-rank rules, so compare sorted.
    $reorderedName = $reordered['properties']['name'];
    ksort($reorderedName);

    expect($direct['properties']['name'])->toBe(['type' => 'string', 'minLength' => 2, 'maxLength' => 100])
        ->and($reorderedName)->toBe(['maxLength' => 100, 'minLength' => 2, 'type' => 'string'])
        ->and($int['properties']['age'])->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => 120]);
});

it('maps type + format + choice + regex + date_format rules', function (): void {
    $schema = convertLaravelRules([
        'email' => 'required|email',
        'id' => 'uuid',
        'status' => 'in:draft,published',
        'slug' => 'regex:/^[a-z]+$/',
        'when' => 'date_format:Y-m-d',
    ])->schema;

    expect($schema['properties']['email'])->toBe(['type' => 'string', 'format' => 'email'])
        ->and($schema['properties']['id'])->toBe(['type' => 'string', 'format' => 'uuid'])
        ->and($schema['properties']['status'])->toBe(['type' => 'string', 'enum' => ['draft', 'published']])
        ->and($schema['properties']['slug'])->toBe(['type' => 'string', 'pattern' => '^[a-z]+$'])
        ->and($schema['properties']['when'])->toBe(['type' => 'string', 'format' => 'date', 'description' => 'Expected format: Y-m-d'])
        ->and($schema['required'])->toBe(['email']);
});

it('switches to multipart and documents the confirmed partner', function (): void {
    $file = convertLaravelRules(['avatar' => 'required|image', 'name' => 'string']);
    $confirmed = convertLaravelRules(['password' => 'required|string|confirmed'])->schema;

    expect($file->mediaType)->toBe('multipart/form-data')
        ->and($file->schema['properties']['avatar'])->toBe(['type' => 'string', 'format' => 'binary', 'description' => 'An image file.'])
        ->and($confirmed['properties'])->toHaveKeys(['password', 'password_confirmation'])
        ->and($confirmed['required'])->toBe(['password', 'password_confirmation']);
});

it('raises an info diagnostic for a rule no transformer handles', function (): void {
    $result = convertLaravelRules(['token' => 'string|starts_with:abc']);

    expect($result->schema['properties']['token'])->toBe(['type' => 'string'])
        ->and($result->diagnostics)->toHaveCount(1)
        ->and($result->diagnostics[0]->code)->toBe('validation.rule-unhandled');
});
