<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Laravel\Integrations\FormRequest\ConstValueToRules;
use Workbench\App\Enums\WidgetStatus;

/**
 * Covers the inline-validation folding crux: turning a statically-recovered {@see ConstValue} (a
 * field's rules from `$request->validate([...])`) into rules, including `Rule::*` descriptors that
 * are folded at the AST level before PHPStan would collapse them to a bare object.
 */
it('folds a pipe-string value into rules', function (): void {
    $rules = (new ConstValueToRules)->fold(ConstValue::scalar('required|string|max:100'));

    expect(array_map(fn ($r) => [$r->name, $r->parameters], $rules))->toBe([
        ['required', []],
        ['string', []],
        ['max', ['100']],
    ]);
});

it('folds an array of scalar tokens and Rule::enum / Rule::in descriptors', function (): void {
    $value = ConstValue::array([
        ConstValue::scalar('required'),
        ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar(WidgetStatus::class)]),
    ]);

    $rules = (new ConstValueToRules)->fold($value);

    expect($rules[0]->name)->toBe('required')
        ->and($rules[1]->name)->toBe('enum')
        ->and($rules[1]->parameters)->toBe(['draft', 'published', 'archived'])
        ->and($rules[1]->note)->toBe(WidgetStatus::class);
});

it('folds Rule::in with an array argument into an in rule', function (): void {
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::in', [
        ConstValue::array([ConstValue::scalar('a'), ConstValue::scalar('b')]),
    ]);

    $rules = (new ConstValueToRules)->fold($value);

    expect($rules)->toHaveCount(1)
        ->and($rules[0]->name)->toBe('in')
        ->and($rules[0]->parameters)->toBe(['a', 'b']);
});

it('ignores a descriptor it does not recognise', function (): void {
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::mystery', [ConstValue::scalar('x')]);

    expect((new ConstValueToRules)->fold($value))->toBe([]);
});
