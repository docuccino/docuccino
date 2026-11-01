<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Validation\UnreadRule;
use Docuccino\Laravel\Tests\Fixtures\Rules\LegacyStringRule;
use Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck;
use Illuminate\Validation\Rule;

/**
 * Whether leaving out an unread rule keeps the rules beside it true. Stated per factory from what Laravel
 * does with the rule it builds: a check can only refuse values, while a conditional, nested or exclude rule
 * — and anything a macro registered — can make the field optional, nullable or unchecked.
 *
 * @return array<string, bool>
 */
function ruleFactoryNarrows(): array
{
    return [
        // Checks on the value, or rule strings that only add a requirement.
        'can' => true, 'array' => true, 'unique' => true, 'exists' => true, 'in' => true, 'notIn' => true,
        'requiredIf' => true, 'requiredUnless' => true, 'prohibitedIf' => true, 'prohibitedUnless' => true,
        'date' => true, 'dateTime' => true, 'email' => true, 'enum' => true, 'file' => true,
        'imageFile' => true, 'dimensions' => true, 'string' => true, 'numeric' => true, 'anyOf' => true,
        'contains' => true, 'doesntContain' => true,
        // Rules chosen at runtime, or applied to each item by a callback.
        'when' => false, 'unless' => false, 'forEach' => false, 'compile' => false,
        // `exclude` stops the field's other rules running at all.
        'excludeIf' => false, 'excludeUnless' => false,
        // Macro plumbing: a call through it builds whatever was registered.
        'macro' => false, 'mixin' => false, 'hasMacro' => false, 'flushMacros' => false, '__callStatic' => false,
    ];
}

it('classifies every Rule factory by what leaving it out does', function (string $method, bool $narrows): void {
    expect(UnreadRule::factoryNarrows('Illuminate\\Validation\\Rule::'.$method))->toBe($narrows)
        ->and(UnreadRule::factoryNarrows('\\Illuminate\\Validation\\Rule::'.strtolower($method)))->toBe($narrows);
})->with(static function (): Generator {
    foreach (ruleFactoryNarrows() as $method => $narrows) {
        yield $method => [$method, $narrows];
    }
});

it('has a row for every static the installed Rule class declares', function (): void {
    // A factory Laravel adds reads as a macro until it has a row, which gives up every field using it.
    $declared = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(Rule::class))->getMethods(ReflectionMethod::IS_STATIC),
    );
    sort($declared);
    $rows = array_keys(ruleFactoryNarrows());
    sort($rows);

    expect($declared)->toHaveCount(count(array_unique($declared)))
        ->and(count($declared))->toBeGreaterThan(20)
        ->and($rows)->toBe($declared);
});

it('takes any factory on a framework rule class as a check, and any other factory as unknown', function (string $factory, bool $narrows): void {
    expect(UnreadRule::factoryNarrows($factory))->toBe($narrows);
})->with([
    'Password::min' => ['Illuminate\\Validation\\Rules\\Password::min', true],
    'Password::defaults' => ['Illuminate\\Validation\\Rules\\Password::defaults', true],
    'File::types' => ['Illuminate\\Validation\\Rules\\File::types', true],
    'an undeclared Rule static (a macro)' => ['Illuminate\\Validation\\Rule::optionalSlug', false],
    'an application helper' => ['App\\Support\\Rules::slug', false],
    'a function name with no class' => ['strtolower', false],
]);

it('takes every framework rule object as a check, except the exclude rules', function (): void {
    // Read off the installed framework, so a rule class Laravel adds is classified the day it ships.
    $classes = array_map(
        static fn (string $file): string => 'Illuminate\\Validation\\Rules\\'.basename($file, '.php'),
        glob(dirname((string) (new ReflectionClass(Rule::class))->getFileName()).'/Rules/*.php') ?: [],
    );

    expect(count($classes))->toBeGreaterThan(20);
    foreach ($classes as $class) {
        $excludes = str_starts_with(substr($class, (int) strrpos($class, '\\') + 1), 'Exclude');

        expect(UnreadRule::objectNarrows($class))->toBe(! $excludes, $class);
    }
});

it('classifies a rule object by what Laravel does with it', function (string $class, bool $narrows): void {
    expect(UnreadRule::objectNarrows($class))->toBe($narrows);
})->with([
    'a closure, run as a check' => ['Closure', true],
    'a ValidationRule, run as a check' => [OpaqueCheck::class, true],
    'a leading backslash' => ['\\'.OpaqueCheck::class, true],
    'a class this build cannot load' => ['App\\Rules\\Missing', true],
    'conditional rules, expanded at runtime' => ['Illuminate\\Validation\\ConditionalRules', false],
    'nested rules, compiled per item' => ['Illuminate\\Validation\\NestedRules', false],
    'an object Laravel casts to a rule string' => [LegacyStringRule::class, false],
]);
