<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Spatie\LaravelData\Attributes\Validation\ObjectValidationAttribute;
use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\ValidationAttribute;
use Spatie\LaravelData\Exceptions\CannotBuildValidationRule;
use Spatie\LaravelData\Support\Validation\RuleDenormalizer;
use Spatie\LaravelData\Support\Validation\ValidationPath;

/*
 * The guard behind the reflector's reading of spatie validation attributes. laravel-data, not Docuccino, says
 * which rule an attribute stands for: it writes the attribute's own `keyword()` and `parameters()` into a rule
 * string (`RuleDenormalizer`). So every attribute the installed package ships is built here, with an argument
 * for each parameter it requires, and the token the reflector reads off it must be the string laravel-data
 * writes. An attribute that hands Laravel a rule object rather than a string carries a row below saying so.
 *
 * Required arguments are written by name and in reverse, so a reading that took them in the order written
 * rather than the order the attribute declares them fails too.
 */

it('reads every validation attribute laravel-data ships to the rule string laravel-data writes for it', function (): void {
    $directory = dirname((string) (new ReflectionClass(ValidationAttribute::class))->getFileName());
    $denormalizer = app(RuleDenormalizer::class);

    // The shipped attributes that build no rule string, each with why the reflector reads it apart.
    $rows = [
        'Rule' => 'Its arguments are rule strings and objects already, split as laravel-data splits them.',
        'Enum' => 'Builds an Enum rule object, read through ruleObjects().',
        'In' => 'Builds an In rule object from its values, read through ruleObjects().',
        'NotIn' => 'Builds a NotIn rule object from its values, read through ruleObjects().',
        'Exists' => 'Builds an Exists rule object; the vocabulary reads it by name alone.',
        'Unique' => 'Builds a Unique rule object; the vocabulary reads it by name alone.',
        'Password' => 'Builds a Password rule object, which Laravel runs as a check.',
        'Dimensions' => 'Builds a Dimensions rule object, which Laravel casts to its own rule string.',
    ];

    $compared = [];
    $apart = [];
    $wrong = [];
    foreach (glob($directory.'/*.php') ?: [] as $file) {
        // The basename is the case-sensitive spelling spatie declares; PHP class names are not.
        $short = basename($file, '.php');
        $class = 'Spatie\\LaravelData\\Attributes\\Validation\\'.$short;
        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        if (isset($rows[$short])) {
            // A row is only true of an attribute that builds no string: the escape hatch, or one whose rule is
            // whatever object getRule() returns.
            expect($class === Rule::class || is_subclass_of($class, ObjectValidationAttribute::class))->toBeTrue($short);
            $apart[] = $short;

            continue;
        }

        [$arguments, $source] = attributeSampleArguments($class);
        try {
            $written = $denormalizer->execute(new $class(...$arguments), ValidationPath::create());
        } catch (CannotBuildValidationRule) {
            // laravel-data refuses these arguments, so every request carrying the field fails, and no rule holds.
            $written = [];
        }

        $read = (new DataClassReflector)->validationTokens(attributeCarrier($class, $source), 'value');

        $compared[] = $short;
        if ($read !== $written) {
            $wrong[] = sprintf('#[%s(%s)]: read %s, laravel-data writes %s', $short, $source, json_encode($read), json_encode($written));
        }
    }

    // A scan that matched nothing would agree with everything. spatie has shipped ~90 of these for the whole of
    // v4, and fewer than ten build objects.
    expect(count($compared))->toBeGreaterThanOrEqual(70)
        ->and($wrong)->toBe([])
        // Every row names an attribute the package ships, so none outlives its attribute.
        ->and(array_values(array_diff(array_keys($rows), $apart)))->toBe([]);
});

it('reads a parameter as laravel-data writes it', function (string $attribute, string $read): void {
    $class = 'Spatie\\LaravelData\\Attributes\\Validation\\'.explode('(', $attribute)[0];
    $carrier = attributeCarrier($class, substr($attribute, strlen(explode('(', $attribute)[0]) + 1, -1));

    expect((new DataClassReflector)->validationTokens($carrier, 'value'))->toBe([$read]);
})->with([
    // A case is the value Laravel compares, a bool is spelled out, and a null is left out.
    'a backed case' => ['RequiredIf("status", \Workbench\App\Enums\WidgetStatus::Published)', 'required_if:status,published'],
    'a bool' => ['AcceptedIf("terms", true)', 'accepted_if:terms,true'],
    'a null' => ['CurrentPassword(null)', 'current_password'],
    'a list' => ['ArrayType(["a", "b"])', 'array:a,b'],
    // A field named by reference is that field, as at the root of the request.
    'a field reference' => ['Same(new \Spatie\LaravelData\Support\Validation\References\FieldReference("other"))', 'same:other'],
    // Only a request resolves a route parameter, so the build states the rule without it.
    'a route parameter' => ['Max(new \Spatie\LaravelData\Support\Validation\References\RouteParameterReference("limit"))', 'max'],
]);

it('reads no rule from an attribute laravel-data cannot build either', function (): void {
    // laravel-data refuses this mode when it builds the rule, so every request carrying the field fails.
    expect((new DataClassReflector)->validationTokens(attributeCarrier('Spatie\\LaravelData\\Attributes\\Validation\\Distinct', '"loose"'), 'value'))->toBe([]);
});
