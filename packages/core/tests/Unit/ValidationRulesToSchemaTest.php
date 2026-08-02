<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\BuiltInRuleTransformers;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\NullTypeEngine;

/**
 * Build a live SchemaContext (the converter is the context) for the rule builder.
 */
function ruleContext(RepresentationPolicy $policy = new RepresentationPolicy): SchemaContext
{
    return new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, $policy);
}

/**
 * Convert a `field => list<string rule name>` shorthand to a RuleSet, splitting `name:a,b` params.
 *
 * @param  array<string, list<string>>  $fields
 */
function ruleSet(array $fields): RuleSet
{
    $out = [];
    foreach ($fields as $field => $rules) {
        $parsed = [];
        foreach ($rules as $rule) {
            $colon = strpos($rule, ':');
            $name = $colon === false ? $rule : substr($rule, 0, $colon);
            $params = $colon === false ? [] : explode(',', substr($rule, $colon + 1));
            $parsed[] = ValidationRule::of($name, $params);
        }
        $out[$field] = $parsed;
    }

    return new RuleSet($out);
}

/**
 * @param  array<string, list<string>>  $fields
 * @return array<string, mixed>
 */
function convertRules(array $fields, RepresentationPolicy $policy = new RepresentationPolicy): array
{
    return DefaultValidationRulesToSchema::withDefaults()->convert(ruleSet($fields), ruleContext($policy))->schema;
}

it('builds an object schema with required members from presence + type rules', function (): void {
    $schema = convertRules([
        'title' => ['required', 'string'],
        'count' => ['integer'],
    ]);

    expect($schema)->toBe([
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
        ],
        'required' => ['title'],
    ]);
});

it('maps string-shaped type rules to formats', function (): void {
    $schema = convertRules([
        'email' => ['email'],
        'id' => ['uuid'],
        'born' => ['date'],
    ]);

    expect($schema['properties'])->toBe([
        'email' => ['type' => 'string', 'format' => 'email'],
        'id' => ['type' => 'string', 'format' => 'uuid'],
        'born' => ['type' => 'string', 'format' => 'date'],
    ]);
});

it('applies size rules type-aware regardless of rule order', function (): void {
    $string = convertRules(['name' => ['string', 'min:2', 'max:100']]);
    $reordered = convertRules(['name' => ['max:100', 'min:2', 'string']]);
    $int = convertRules(['age' => ['integer', 'between:1,120']]);
    $list = convertRules(['tags' => ['array', 'max:5']]);

    // Author order only affects key order within a rank (the canonicalizer sorts keys at assembly),
    // so the same keywords/values are produced either way.
    $reorderedName = $reordered['properties']['name'];
    ksort($reorderedName);

    expect($string['properties']['name'])->toBe(['type' => 'string', 'minLength' => 2, 'maxLength' => 100])
        ->and($reorderedName)->toBe(['maxLength' => 100, 'minLength' => 2, 'type' => 'string'])
        ->and($int['properties']['age'])->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => 120])
        ->and($list['properties']['tags'])->toBe(['type' => 'array', 'maxItems' => 5]);
});

it('emits size:n as an exact bound', function (): void {
    $schema = convertRules(['code' => ['string', 'size:6']]);

    expect($schema['properties']['code'])->toBe(['type' => 'string', 'minLength' => 6, 'maxLength' => 6]);
});

it('turns in rules into enum schemas, typing numeric value sets as integers', function (): void {
    $strings = convertRules(['status' => ['in:draft,published,archived']]);
    $numbers = convertRules(['level' => ['in:1,2,3']]);

    expect($strings['properties']['status'])->toBe(['type' => 'string', 'enum' => ['draft', 'published', 'archived']])
        ->and($numbers['properties']['level'])->toBe(['type' => 'integer', 'enum' => [1, 2, 3]]);
});

it('carries a folded enum note as the description', function (): void {
    $rule = ValidationRule::of('enum', ['open', 'closed'], 'App\\Enums\\State');
    $schema = DefaultValidationRulesToSchema::withDefaults()->convert(new RuleSet(['state' => [$rule]]), ruleContext())->schema;

    expect($schema['properties']['state'])->toBe([
        'type' => 'string',
        'enum' => ['open', 'closed'],
        'description' => 'App\\Enums\\State',
    ]);
});

it('maps regex to a delimiter-stripped pattern', function (): void {
    $schema = convertRules(['slug' => ['regex:/^[a-z]+$/']]);

    expect($schema['properties']['slug'])->toBe(['type' => 'string', 'pattern' => '^[a-z]+$']);
});

it('maps date_format to a date or date-time format with the raw pattern noted', function (): void {
    $dateOnly = convertRules(['on' => ['date_format:Y-m-d']]);
    $dateTime = convertRules(['at' => ['date_format:Y-m-d H:i:s']]);

    expect($dateOnly['properties']['on'])->toBe(['type' => 'string', 'format' => 'date', 'description' => 'Expected format: Y-m-d'])
        ->and($dateTime['properties']['at'])->toBe(['type' => 'string', 'format' => 'date-time', 'description' => 'Expected format: Y-m-d H:i:s']);
});

it('switches to multipart and binary for file and image rules', function (): void {
    $result = DefaultValidationRulesToSchema::withDefaults()->convert(
        ruleSet(['avatar' => ['required', 'image'], 'doc' => ['file']]),
        ruleContext(),
    );

    expect($result->mediaType)->toBe('multipart/form-data')
        ->and($result->schema['properties']['avatar'])->toBe(['type' => 'string', 'format' => 'binary', 'description' => 'An image file.'])
        ->and($result->schema['properties']['doc'])->toBe(['type' => 'string', 'format' => 'binary'])
        ->and($result->schema['required'])->toBe(['avatar']);
});

it('documents the confirmed partner field mirroring type and requiredness', function (): void {
    $schema = convertRules(['password' => ['required', 'string', 'confirmed']]);

    expect($schema['properties'])->toBe([
        'password' => ['type' => 'string'],
        'password_confirmation' => ['type' => 'string'],
    ])->and($schema['required'])->toBe(['password', 'password_confirmation']);
});

it('nests dot notation and wildcard arrays', function (): void {
    $schema = convertRules([
        'author.name' => ['required', 'string'],
        'tags.*' => ['string'],
        'items.*.id' => ['required', 'integer'],
    ]);

    expect($schema['properties']['author'])->toBe([
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string']],
        'required' => ['name'],
    ])->and($schema['properties']['tags'])->toBe([
        'type' => 'array',
        'items' => ['type' => 'string'],
    ])->and($schema['properties']['items'])->toBe([
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ],
    ]);
});

it('expresses nullable per the representation policy', function (): void {
    $typeArray = convertRules(['nick' => ['nullable', 'string']]);
    $anyOf = convertRules(['nick' => ['nullable', 'string']], new RepresentationPolicy(nullable: 'anyof'));

    expect($typeArray['properties']['nick'])->toBe(['type' => ['string', 'null']])
        ->and($anyOf['properties']['nick'])->toBe(['anyOf' => [['type' => 'string'], ['type' => 'null']]]);
});

it('leaves an unknown rule permissive and raises an info diagnostic', function (): void {
    $result = DefaultValidationRulesToSchema::withDefaults()->convert(
        ruleSet(['token' => ['string', 'starts_with:abc']]),
        ruleContext(),
    );

    expect($result->schema['properties']['token'])->toBe(['type' => 'string'])
        ->and($result->diagnostics)->toHaveCount(1)
        ->and($result->diagnostics[0]->code)->toBe('validation.rule-unhandled')
        ->and($result->diagnostics[0]->message)->toContain('starts_with');
});

it('lets a custom transformer intercept a rule ahead of the built-ins', function (): void {
    $custom = new class implements RuleTransformer
    {
        public function supports(ValidationRule $rule): bool
        {
            return $rule->name === 'starts_with';
        }

        public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
        {
            $field->setType('string');
            $field->set('pattern', '^'.preg_quote($rule->parameter() ?? '', '/'));
        }
    };

    $engine = new DefaultValidationRulesToSchema([$custom, ...BuiltInRuleTransformers::all()]);
    $result = $engine->convert(ruleSet(['token' => ['string', 'starts_with:abc']]), ruleContext());

    expect($result->schema['properties']['token'])->toBe(['type' => 'string', 'pattern' => '^abc'])
        ->and($result->diagnostics)->toBe([]);
});

it('returns an empty schema for an empty rule set', function (): void {
    $result = DefaultValidationRulesToSchema::withDefaults()->convert(new RuleSet, ruleContext());

    expect($result->isEmpty())->toBeTrue()
        ->and($result->mediaType)->toBe('application/json');
});
