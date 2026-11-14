<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Laravel\Integrations\FormRequest\ConstValueToRules;
use Docuccino\Laravel\Integrations\Validation\LiveRuleValue;
use Docuccino\Laravel\Tests\Fixtures\Rules\TenantMember;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\ArrayRule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\ExcludeIf;
use Illuminate\Validation\Rules\ExcludeUnless;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;
use Illuminate\Validation\Rules\Unique;
use Workbench\App\Enums\WidgetStatus;

/*
 * A rule object an attribute was given already exists, so it is read back from its state rather than from
 * the `new` that built it. Each row pairs the object with the value the engine folds that same `new` to,
 * so a rule handed to `#[Rule(…)]` reads exactly as the one written in a rules array.
 */
$rules = 'Illuminate\\Validation\\Rules\\';
$case = static fn (string $name): ConstValue => ConstValue::enumCase(WidgetStatus::class, $name);
$list = static fn (ConstValue ...$items): ConstValue => ConstValue::array(array_values($items));
$string = ConstValue::scalar(...);

$objects = [
    'an enum' => [static fn () => new Enum(WidgetStatus::class), ConstValue::instance(Enum::class, [$string(WidgetStatus::class)])],
    'an enum narrowed by only' => [
        static fn () => (new Enum(WidgetStatus::class))->only([WidgetStatus::Draft]),
        ConstValue::instance(Enum::class, [$string(WidgetStatus::class)])->withChainedCall('only', [$list($case('Draft'))]),
    ],
    'an enum narrowed by except' => [
        static fn () => (new Enum(WidgetStatus::class))->except(WidgetStatus::Archived),
        ConstValue::instance(Enum::class, [$string(WidgetStatus::class)])->withChainedCall('except', [$case('Archived')]),
    ],
    'a choice list' => [static fn () => new In(['a', WidgetStatus::Draft]), ConstValue::instance(In::class, [$list($string('a'), $case('Draft'))])],
    'a choice list of separate arguments' => [static fn () => new In('a', 'b'), ConstValue::instance(In::class, [$string('a'), $string('b')])],
    'an exclusion list' => [static fn () => new NotIn(['x']), ConstValue::instance(NotIn::class, [$list($string('x'))])],
    'an array naming its keys' => [static fn () => new ArrayRule(['name']), ConstValue::instance(ArrayRule::class, [$list($string('name'))])],
    'a bare array' => [static fn () => new ArrayRule, ConstValue::instance(ArrayRule::class, [])],
    'a row that must exist' => [static fn () => new Exists('users', 'id'), ConstValue::instance(Exists::class, [$string('users'), $string('id')])],
    'a value that must be unique' => [static fn () => new Unique('users'), ConstValue::instance(Unique::class, [$string('users')])],
    'an exclusion that holds' => [static fn () => new ExcludeIf(true), ConstValue::instance(ExcludeIf::class, [$string(true)])],
    'an exclusion that does not' => [static fn () => new ExcludeIf(false), ConstValue::instance(ExcludeIf::class, [$string(false)])],
    'an exclusion on a closure' => [
        static fn () => new ExcludeIf(static fn (): bool => true),
        ConstValue::instance(ExcludeIf::class, [ConstValue::unknown('a closure')]),
    ],
    'an exclusion unless' => [static fn () => new ExcludeUnless(false), ConstValue::instance(ExcludeUnless::class, [$string(false)])],
    // Laravel 13's own objects for the field-presence exclusions.
    'an exclusion with a field' => [
        static fn () => new ($rules.'ExcludeWith')('other'),
        ConstValue::instance($rules.'ExcludeWith', [$string('other')]),
    ],
    'an exclusion without fields' => [
        static fn () => new ($rules.'ExcludeWithout')(['a', 'b']),
        ConstValue::instance($rules.'ExcludeWithout', [$list($string('a'), $string('b'))]),
    ],
];

it('reads a framework rule object as the new that built it', function (Closure $live, ConstValue $written): void {
    if (! class_exists((string) $written->class)) {
        $this->markTestSkipped('The installed Laravel has no '.$written->class.'.');
    }

    $read = (new ConstValueToRules)->fold(LiveRuleValue::of($live()));

    expect($read)->toEqual((new ConstValueToRules)->fold($written));
})->with($objects);

it('reads every framework object the rules-array fold reads by its arguments', function () use ($objects): void {
    // The fold's own tables are the domain; each class in them owes a row above and a reading of its state.
    $domain = [];
    foreach (['FACTORY_OBJECTS', 'EXCLUDE_OBJECTS', 'EXCLUDE_FIELD_OBJECTS'] as $table) {
        $domain = [...$domain, ...array_keys((array) (new ReflectionClassConstant(ConstValueToRules::class, $table))->getValue())];
    }

    $rows = array_values(array_unique(array_map(static fn (array $row): string => (string) $row[1]->class, $objects)));
    $read = array_keys((array) (new ReflectionClassConstant(LiveRuleValue::class, 'ARGUMENTS'))->getValue());

    expect($domain)->toHaveCount(10)
        ->and($rows)->toEqualCanonicalizing($domain)
        ->and($read)->toEqualCanonicalizing($domain);
});

it('narrows an enum rule to the cases Laravel takes', function (Closure $rule): void {
    $values = (new ConstValueToRules)->fold(LiveRuleValue::of($rule()))[0]->parameters;

    foreach (WidgetStatus::cases() as $case) {
        $taken = Validator::make(['f' => $case->value], ['f' => [$rule()]])->passes();

        expect(in_array($case->value, $values, true))->toBe($taken, $case->value);
    }
})->with([
    'only' => [static fn () => (new Enum(WidgetStatus::class))->only([WidgetStatus::Draft, WidgetStatus::Published])],
    'except' => [static fn () => (new Enum(WidgetStatus::class))->except([WidgetStatus::Archived])],
    // Laravel consults `except` only while `only` is empty, so `only` is the whole narrowing.
    'only beside except' => [static fn () => (new Enum(WidgetStatus::class))->only([WidgetStatus::Draft, WidgetStatus::Archived])->except([WidgetStatus::Archived])],
]);

it('reads any other value as the constant it is', function (mixed $value, ConstValue $expected): void {
    expect(LiveRuleValue::of($value))->toEqual($expected);
})->with([
    'a rule string' => ['required', ConstValue::scalar('required')],
    'null' => [null, ConstValue::scalar(null)],
    'a case' => [WidgetStatus::Draft, ConstValue::enumCase(WidgetStatus::class, 'Draft')],
    'a keyed list' => [['a' => 'x', 'b' => 1], ConstValue::array([ConstValue::scalar('x'), ConstValue::scalar(1)])],
    // A class is all a `new` names for an object no fold reads by its arguments.
    'an application rule' => [new TenantMember, ConstValue::instance(TenantMember::class, [])],
    'a closure' => [static fn (): bool => true, ConstValue::instance(Closure::class, [])],
]);

it('reads a value no rule list can hold as unknown', function (): void {
    $stream = fopen('php://memory', 'r');

    expect(LiveRuleValue::of($stream)->kind)->toBe(ConstValue::KIND_UNKNOWN);
});
