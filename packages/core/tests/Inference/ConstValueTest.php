<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Inference;

use Docuccino\Core\Inference\ConstValue;

it('round-trips a scalar const value', function (): void {
    $value = ConstValue::scalar('status');

    expect(ConstValue::fromArray($value->toArray())->toArray())->toBe($value->toArray())
        ->and($value->render())->toBe("'status'");
});

it('folds a factory call descriptor with nested args', function (): void {
    $value = ConstValue::descriptor('AllowedFilter::exact', [ConstValue::scalar('status')]);

    expect($value->isDescriptor())->toBeTrue()
        ->and($value->render())->toBe("AllowedFilter::exact('status')")
        ->and(ConstValue::fromArray($value->toArray())->render())->toBe($value->render());
});

it('round-trips an array of mixed const values', function (): void {
    $value = ConstValue::array([
        ConstValue::scalar('name'),
        ConstValue::descriptor('AllowedFilter::partial', [ConstValue::scalar('email')]),
    ]);

    expect($value->isArray())->toBeTrue()
        ->and($value->render())->toBe("['name', AllowedFilter::partial('email')]")
        ->and(ConstValue::fromArray($value->toArray())->toArray())->toBe($value->toArray());
});

it('renders scalar kinds distinctly', function (): void {
    expect(ConstValue::scalar(true)->render())->toBe('true')
        ->and(ConstValue::scalar(false)->render())->toBe('false')
        ->and(ConstValue::scalar(null)->render())->toBe('null')
        ->and(ConstValue::scalar(25)->render())->toBe('25');
});

it('carries a reason on an unknown value', function (): void {
    $value = ConstValue::unknown('non-constant');

    expect($value->render())->toBe('<unknown: non-constant>')
        ->and(ConstValue::fromArray($value->toArray())->render())->toBe($value->render());
});
