<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Support\FormatSamples;
use Opis\JsonSchema\Validator as OpisValidator;

/**
 * The synthesized property `example`, driven through the public chain the way every recovery
 * integration reaches it. Core knows no rule names, so the transformer here speaks in KEYWORDS: a
 * `kw` rule writes one, a `propose` rule offers a value only it could know. That is exactly the split
 * the Laravel vocabulary uses — its own table is pinned in ValidationVocabularyTest.
 */
function exampleContext(): SchemaContext
{
    return new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);
}

/**
 * Convert one field described as keyword writes and proposals, and return the property schema.
 *
 * `['kw', 'type', 'string']` sets a keyword; `['propose', <json>]` proposes an example value, with
 * `null` standing for the suppression form.
 *
 * @param  list<array<int, string|null>>  $steps
 * @return array<string, mixed>
 */
function exampleProperty(array $steps): array
{
    $transformer = new class implements RuleTransformer
    {
        public function supports(ValidationRule $rule): bool
        {
            return in_array($rule->name, $this->handledRuleNames(), true);
        }

        public function handledRuleNames(): array
        {
            return ['kw', 'propose'];
        }

        public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
        {
            if ($rule->name === 'propose') {
                $raw = $rule->parameter();
                $field->proposeExample($raw === null ? null : json_decode($raw, true));

                return;
            }

            $field->set((string) $rule->parameter(0), json_decode((string) $rule->parameter(1), true));
        }
    };

    $rules = array_map(
        static fn (array $step): ValidationRule => ValidationRule::of(
            (string) $step[0],
            array_map(static fn (?string $p): string => (string) $p, array_slice($step, 1)),
        ),
        $steps,
    );

    // A proposal's null form has to survive the string-parameter journey, so it travels as no parameter.
    $rules = array_map(
        static fn (ValidationRule $rule, int $i): ValidationRule => $steps[$i][0] === 'propose' && $steps[$i][1] === null
            ? ValidationRule::of('propose')
            : $rule,
        $rules,
        array_keys($rules),
    );

    $schema = (new DefaultValidationRulesToSchema([$transformer]))
        ->convert(new RuleSet(['f' => $rules]), exampleContext())
        ->schema;

    $property = $schema['properties']['f'] ?? [];

    return is_array($property) ? $property : [];
}

/** `['type', '"string"']` shorthand for one keyword write. */
function kw(string $keyword, string $json): array
{
    return ['kw', $keyword, $json];
}

/**
 * The keyword → example table, plus every way it declines. A row whose expectation is null asserts
 * NO example was published, which is the degradation half of the standard.
 */
it('derives an example from what the keywords pin, and nothing where they pin only a type', function (array $steps, mixed $expected): void {
    $property = exampleProperty($steps);

    if ($expected === null) {
        expect($property)->not->toHaveKey('example');

        return;
    }

    expect($property['example'] ?? null)->toBe($expected);
})->with([
    // Booleans: the whole domain is two values, so `true` says as much as anything can.
    'boolean' => [[kw('type', '"boolean"')], true],

    // An enum's first member — a list's order is authored, and every other reader shows the same one.
    'enum (strings)' => [[kw('type', '"string"'), kw('enum', '["draft","published"]')], 'draft'],
    'enum (integers)' => [[kw('type', '"integer"'), kw('enum', '[3,4]')], 3],
    'enum beats the format sample' => [[kw('type', '"string"'), kw('format', '"email"'), kw('enum', '["a@b.test"]')], 'a@b.test'],

    // Formats: the sample the table holds.
    'format email' => [[kw('type', '"string"'), kw('format', '"email"')], 'user@example.com'],
    'format date' => [[kw('type', '"string"'), kw('format', '"date"')], '2024-01-01'],
    'format uuid' => [[kw('type', '"string"'), kw('format', '"uuid"')], '3fa85f64-5717-4562-b3fc-2c963f66afa6'],

    // Numeric bounds: the lowest legal value at or above the seed.
    'minimum' => [[kw('type', '"integer"'), kw('minimum', '18')], 18],
    'maximum below the seed' => [[kw('type', '"integer"'), kw('maximum', '0')], 0],
    'maximum above the seed' => [[kw('type', '"integer"'), kw('maximum', '99')], 1],
    'exclusiveMinimum' => [[kw('type', '"integer"'), kw('exclusiveMinimum', '5')], 6],
    'exclusiveMaximum' => [[kw('type', '"integer"'), kw('exclusiveMaximum', '1')], 0],
    'multipleOf' => [[kw('type', '"integer"'), kw('multipleOf', '5')], 5],
    'float minimum keeps its decimal' => [[kw('type', '"number"'), kw('minimum', '2.5')], 2.5],
    'integer type never publishes a float' => [[kw('type', '"integer"'), kw('multipleOf', '2'), kw('minimum', '3')], 4],

    // Lengths: a prefix of the filler at a length the bounds allow.
    'maxLength above the preferred length' => [[kw('type', '"string"'), kw('maxLength', '255')], 'example'],
    'maxLength below it truncates' => [[kw('type', '"string"'), kw('maxLength', '5')], 'examp'],
    'minLength above it extends' => [[kw('type', '"string"'), kw('minLength', '12')], 'example-valu'],
    'size (both bounds equal)' => [[kw('type', '"string"'), kw('minLength', '3'), kw('maxLength', '3')], 'exa'],

    // A pattern counts as pinning something: the filler prefix either matches it or is refused.
    'pattern the filler matches' => [[kw('type', '"string"'), kw('pattern', '"^[a-z-]+$"')], 'example'],
    'pattern the filler does not match' => [[kw('type', '"string"'), kw('pattern', '"^\\\\d{5}$"')], null],

    // Nothing beyond the base type: `type` already tells a generator that much.
    'bare string' => [[kw('type', '"string"')], null],
    'bare integer' => [[kw('type', '"integer"')], null],
    'bare number' => [[kw('type', '"number"')], null],
    'unknown format' => [[kw('type', '"string"'), kw('format', '"iban"')], null],
    'no type at all' => [[kw('description', '"Just prose."')], null],

    // Shapes no scalar illustrates, or that already state their value.
    'const states the value itself' => [[kw('type', '"boolean"'), kw('const', 'true')], null],
    'binary is an upload, not an illustration' => [[kw('type', '"string"'), kw('format', '"binary"'), kw('maxLength', '32')], null],
    'array' => [[kw('type', '"array"'), kw('minItems', '1')], null],
    'object' => [[kw('type', '"object"')], null],

    // Contradictions: no value satisfies them, so none is published.
    'bounds that cross' => [[kw('type', '"integer"'), kw('minimum', '10'), kw('maximum', '5')], null],
    'lengths that cross' => [[kw('type', '"string"'), kw('minLength', '9'), kw('maxLength', '4')], null],
    'length floor above the cap' => [[kw('type', '"string"'), kw('minLength', '200')], null],
    'multipleOf with no room under the ceiling' => [[kw('type', '"integer"'), kw('multipleOf', '10'), kw('maximum', '4')], null],
    'enum member the other keywords refuse' => [[kw('type', '"integer"'), kw('enum', '["nine"]')], null],
    'format sample the length bound refuses' => [[kw('type', '"string"'), kw('format', '"email"'), kw('maxLength', '4')], null],
]);

it('publishes a proposal the schema agrees with, and refuses one it does not', function (): void {
    // A value only the rule could know — a wire date format the schema cannot carry.
    expect(exampleProperty([kw('type', '"string"'), ['propose', '"01/01/2024"']])['example'] ?? null)->toBe('01/01/2024');

    // The same proposal against a `format` that contradicts it: the schema is what a client validates
    // against, so the proposal is dropped rather than published beside a keyword it fails.
    expect(exampleProperty([kw('type', '"string"'), kw('format', '"date"'), ['propose', '"01/01/2024"']]))
        ->not->toHaveKey('example');

    // A proposal beats the derivation, whichever order the rules arrive in.
    expect(exampleProperty([['propose', '"UTC"'], kw('type', '"string"'), kw('maxLength', '64')])['example'] ?? null)->toBe('UTC');
});

it('lets a rule withdraw the example entirely, and keeps the withdrawal final', function (): void {
    // A file upload, a decimal-places constraint: the rule says no value here is truthful.
    expect(exampleProperty([kw('type', '"number"'), ['propose', null]]))->not->toHaveKey('example');

    // Final: a later proposal cannot revive it, in either order.
    expect(exampleProperty([kw('type', '"string"'), ['propose', null], ['propose', '"x"']]))->not->toHaveKey('example');
    expect(exampleProperty([kw('type', '"string"'), ['propose', '"x"'], ['propose', null]]))->not->toHaveKey('example');
});

it('never overwrites an example the rules stated outright', function (): void {
    // An author's `example` keyword is the contract as they wrote it — neither the format's sample nor
    // a proposal displaces it, and it is not held to the schema here (the example audit does that).
    expect(exampleProperty([kw('type', '"string"'), kw('format', '"email"'), kw('example', '"ops@example.test"')])['example'] ?? null)
        ->toBe('ops@example.test');
    expect(exampleProperty([kw('type', '"string"'), kw('example', '"mine"'), ['propose', '"theirs"']])['example'] ?? null)
        ->toBe('mine');
});

it('synthesizes into array items and nested objects, never onto the container itself', function (): void {
    $rules = new RuleSet([
        'tags.*' => [ValidationRule::of('kw', ['type', '"string"']), ValidationRule::of('kw', ['format', '"email"'])],
        'author.name' => [ValidationRule::of('kw', ['type', '"string"']), ValidationRule::of('kw', ['maxLength', '40'])],
    ]);

    $transformer = new class implements RuleTransformer
    {
        public function supports(ValidationRule $rule): bool
        {
            return $rule->name === 'kw';
        }

        public function handledRuleNames(): array
        {
            return ['kw'];
        }

        public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
        {
            $field->set((string) $rule->parameter(0), json_decode((string) $rule->parameter(1), true));
        }
    };

    $schema = (new DefaultValidationRulesToSchema([$transformer]))->convert($rules, exampleContext())->schema;

    expect($schema['properties']['tags'])->toBe([
        'type' => 'array',
        'items' => ['type' => 'string', 'format' => 'email', 'example' => 'user@example.com'],
    ])->and($schema['properties']['author'])->toBe([
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string', 'maxLength' => 40, 'example' => 'example']],
    ]);
});

/**
 * Determinism is the product feature the whole synthesis rides on: two conversions of one rule set
 * must be byte-identical, and neither the process timezone nor the locale may reach a value.
 */
it('produces byte-identical bytes across builds, timezones and locales', function (): void {
    $steps = [
        kw('type', '"string"'), kw('format', '"date-time"'), kw('minLength', '4'),
    ];

    $timezone = date_default_timezone_get();
    $locale = setlocale(LC_ALL, '0');

    try {
        date_default_timezone_set('UTC');
        setlocale(LC_ALL, 'C');
        $first = json_encode(exampleProperty($steps));

        date_default_timezone_set('Pacific/Kiritimati');
        setlocale(LC_ALL, 'C.UTF-8', 'en_US.UTF-8', 'C');
        $second = json_encode(exampleProperty($steps));
    } finally {
        date_default_timezone_set($timezone);
        if (is_string($locale)) {
            setlocale(LC_ALL, $locale);
        }
    }

    expect($first)->toBe($second)
        ->and($first)->toContain('2024-01-01T00:00:00Z');
});

/**
 * The format table is a mapping table, so every entry gets a row: the sample exists and the format it
 * is filed under accepts it. Unknown formats degrade to null, which is what stops a made-up sample
 * reaching the document.
 */
it('answers for every format it lists with a value that format accepts', function (string $format): void {
    $sample = FormatSamples::for($format);

    expect($sample)->toBeString();

    $schema = json_decode((string) json_encode(['type' => 'string', 'format' => $format]));

    expect((new OpisValidator)->validate($sample, $schema)->isValid())->toBeTrue();
})->with(array_map(static fn (string $f): array => [$f], FormatSamples::formats()));

it('lists exactly the formats it is expected to answer for, and nothing for any other', function (): void {
    // The dataset above only proves the rows the table HAS; this fails when one is added silently.
    expect(FormatSamples::formats())->toBe([
        'date-time', 'date', 'time', 'duration', 'email', 'idn-email', 'uuid', 'ulid',
        'uri', 'uri-reference', 'url', 'hostname', 'ip', 'ipv4', 'ipv6', 'byte', 'binary', 'password',
    ]);

    expect(FormatSamples::for('iban'))->toBeNull()
        ->and(FormatSamples::for(''))->toBeNull();
});
