<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
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
