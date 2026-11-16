<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Laravel\Integrations\Validation\CastRuleObject;
use Docuccino\Laravel\Tests\Fixtures\Rules\CallRecorder;
use Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck;
use Docuccino\Laravel\Tests\Fixtures\Rules\RecordedModel;
use Illuminate\Validation\Rules\Date;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\StringRule;
use Workbench\App\Enums\WidgetStatus;

/*
 * A framework rule object written with literal values is built and read by Laravel's own parser. Building runs
 * Laravel's code on those values and nothing else, so every way a value could reach application code — a macro,
 * a callable, a model named as a table — is refused, and so is any object the fold could not rebuild exactly.
 */
beforeEach(function (): void {
    CallRecorder::$calls = 0;
});

afterEach(function (): void {
    Date::flushMacros();
});

it('reads a rule object built from literal values as the rule strings Laravel splits it into', function (ConstValue $written, array $expected): void {
    $strings = $written->isDescriptor()
        ? CastRuleObject::fromFactory((string) $written->factory, $written->args, $written->chain)
        : CastRuleObject::fromNew((string) $written->class, $written->args, $written->chain);

    expect($strings)->toBe($expected);
})->with([
    'Rule::string() bounded' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::string', [])->withChainedCall('min', [ConstValue::scalar(2)])->withChainedCall('max', [ConstValue::scalar(40)]), ['string', 'min:2', 'max:40']],
    'new StringRule chained' => [ConstValue::instance('\\Illuminate\\Validation\\Rules\\StringRule', [])->withChainedCall('alphaDash', []), ['string', 'alpha_dash']],
    'Rule::date() in a format, after today' => [ConstValue::descriptor('\\Illuminate\\Validation\\Rule::date', [])->withChainedCall('format', [ConstValue::scalar('Y-m-d')])->withChainedCall('afterToday', []), ['date_format:Y-m-d', 'after:today']],
    'Rule::numeric() as a bounded integer' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::numeric', [])->withChainedCall('integer', [])->withChainedCall('min', [ConstValue::scalar(1)]), ['numeric', 'integer', 'min:1']],
    'Rule::contains() over a list' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::contains', [ConstValue::array([ConstValue::scalar('a'), ConstValue::scalar('b')])]), ['contains:"a","b"']],
    'new Dimensions with no constraints, then one set' => [ConstValue::instance('Illuminate\\Validation\\Rules\\Dimensions', [])->withChainedCall('minWidth', [ConstValue::scalar(10)]), ['dimensions:min_width=10']],
]);

it('builds nothing from a value it cannot read or a call it would not make, and runs no application code', function (Closure $build): void {
    Date::macro('recorded', function (): Date {
        CallRecorder::record();

        return $this;
    });

    expect($build())->toBeNull()
        ->and(CallRecorder::$calls)->toBe(0);
})->with([
    // The value would be guessed.
    'an argument nothing can read' => [static fn (): ?array => CastRuleObject::fromFactory('Illuminate\\Validation\\Rule::contains', [ConstValue::unknown('variable')], [])],
    'an argument spread in' => [static fn (): ?array => CastRuleObject::fromNew('Illuminate\\Validation\\Rules\\Contains', [ConstValue::spread('unplaceable')], [])],
    'an enum case' => [static fn (): ?array => CastRuleObject::fromNew('Illuminate\\Validation\\Rules\\Contains', [ConstValue::enumCase(WidgetStatus::class, 'Draft')], [])],
    'a chained bound nothing can read' => [static fn (): ?array => CastRuleObject::fromFactory('Illuminate\\Validation\\Rule::string', [], [['method' => 'max', 'args' => [ConstValue::unknown('call')]]])],
    'too few arguments to a chained call' => [static fn (): ?array => CastRuleObject::fromFactory('Illuminate\\Validation\\Rule::string', [], [['method' => 'between', 'args' => [ConstValue::scalar(1)]]])],
    // A folded array has lost its keys, so a constructor reading them would be rebuilt wrong.
    'a constructor taking a keyed array' => [static fn (): ?array => CastRuleObject::fromNew('Illuminate\\Validation\\Rules\\Dimensions', [ConstValue::array([ConstValue::scalar(10)])], [])],
    'a factory passing a keyed array on' => [static fn (): ?array => CastRuleObject::fromFactory('Illuminate\\Validation\\Rule::dimensions', [ConstValue::array([ConstValue::scalar(10)])], [])],
    // Each of these would run the application's code.
    'a macro' => [static fn (): ?array => CastRuleObject::fromFactory('Illuminate\\Validation\\Rule::date', [], [['method' => 'recorded', 'args' => []]])],
    'a callable named by a string' => [static fn (): ?array => CastRuleObject::fromFactory('Illuminate\\Validation\\Rule::string', [], [['method' => 'when', 'args' => [ConstValue::scalar(true), ConstValue::scalar(CallRecorder::class.'::record')]]])],
    'a model named as a table, with new' => [static fn (): ?array => CastRuleObject::fromNew('Illuminate\\Validation\\Rules\\Exists', [ConstValue::scalar(RecordedModel::class)], [])],
    'a model named as a table, by the factory' => [static fn (): ?array => CastRuleObject::fromFactory('Illuminate\\Validation\\Rule::unique', [ConstValue::scalar(RecordedModel::class)], [])],
    // Not one Laravel reads as a string.
    'a check Laravel runs' => [static fn (): ?array => CastRuleObject::fromNew('Illuminate\\Validation\\Rules\\Password', [ConstValue::scalar(8)], [])],
    'an application rule' => [static fn (): ?array => CastRuleObject::fromNew(OpaqueCheck::class, [], [])],
    'a macro on Rule' => [static fn (): ?array => CastRuleObject::fromFactory('Illuminate\\Validation\\Rule::optionalSlug', [], [])],
    'a static of another class' => [static fn (): ?array => CastRuleObject::fromFactory('App\\Support\\Rule::string', [], [])],
    'a chained call that returns no rule' => [static fn (): ?array => CastRuleObject::fromFactory('Illuminate\\Validation\\Rule::string', [], [['method' => '__toString', 'args' => []]])],
]);

it('runs a callable that is no literal of the build: the guard is the reason none runs above', function (): void {
    // The macro and the callable above are refused by the build alone; called directly, each runs.
    Date::macro('recorded', function (): Date {
        CallRecorder::record();

        return $this;
    });

    (new Date)->recorded();
    expect(CallRecorder::$calls)->toBe(1);

    (new StringRule)->when(true, CallRecorder::class.'::record');
    expect(CallRecorder::$calls)->toBe(2);

    new Exists(RecordedModel::class);
    expect(CallRecorder::$calls)->toBeGreaterThan(2);
});
