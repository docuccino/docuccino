<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\Layer;
use Docuccino\Core\Patch\PatchGuard;
use Docuccino\Core\Patch\PatchResult;
use Docuccino\Core\Support\EmptyObject;
use Docuccino\Core\Support\Json;

/**
 * The shared `{}`. A draft assembled as PHP arrays has no other way to say "an object with nothing in
 * it", and minting one per site made `{}` the one value in a draft never `===` an equal `{}` beside it
 * — which is a phantom `overrode` entry out of the patch guard and two whole builds that cannot be
 * compared. So the guarantees are: one instance, `{}` on every writer, and nothing can change it.
 */
it('is one instance for the whole process', function (): void {
    expect(EmptyObject::get())->toBe(EmptyObject::get());
});

it('is a stdClass, so every reader that matches on one keeps matching', function (): void {
    // The reason this is a subclass rather than a value type of its own: every site that classifies a
    // draft value does it with `instanceof stdClass`, and none of them had to change.
    expect(EmptyObject::get())->toBeInstanceOf(stdClass::class)
        ->and((array) EmptyObject::get())->toBe([])
        ->and(get_object_vars(EmptyObject::get()))->toBe([]);
});

it('writes as an empty object through every writer that emits one', function (): void {
    expect(json_encode(EmptyObject::get()))->toBe('{}')
        ->and((new CanonicalJsonSerializer)->serialize(EmptyObject::get()))->toBe("{}\n")
        ->and((new CanonicalJsonSerializer)->serialize(['example' => EmptyObject::get()]))
        ->toBe("{\n  \"example\": {}\n}\n")
        // The fingerprinter too, and — the half that matters — differently from an empty list, or a
        // component deduped by content would merge `{}` with `[]`.
        ->and(Json::stable(EmptyObject::get()))->toBe('{}')
        ->and(Json::stable(EmptyObject::get()))->not->toBe(Json::stable([]));
});

it('refuses to be written to, because one write would reach every {} in the document', function (): void {
    // The one guarantee the type system will not give a shared stdClass, so it is enforced here rather
    // than asserted about the codebase: a stray member added anywhere would appear everywhere.
    expect(static function (): void {
        // @phpstan-ignore-next-line property.notFound (the point of the test is that this is refused)
        EmptyObject::get()->poisoned = 'everywhere';
    })->toThrow(LogicException::class, 'immutable');

    expect((array) EmptyObject::get())->toBe([]);
});

it('refuses to be cloned, because a copy is a second {} that is not === the first', function (): void {
    // `clone` is the silent way back to the defect, and there is none in core or the adapter today —
    // blocked here so a future one fails loudly instead of splitting the instance again.
    $clone = static fn (): object => clone EmptyObject::get();

    expect($clone)->toThrow(Error::class);
});

it('stops the patch guard recording a shadow nobody lost', function (): void {
    // Two producers writing the same `{}` agree, and an `overrode` trail is for values that were
    // actually displaced. With a fresh instance each, `!==` said they differed and `--provenance=full`
    // grew an entry claiming a producer lost an example to an identical one.
    $guard = new PatchGuard;
    $high = new Contribution(Layer::Attribute, 'a');
    $low = new Contribution(Layer::Inference, 'b');

    expect($guard->apply('example', EmptyObject::get(), $high))->toBe(PatchResult::Accepted)
        ->and($guard->apply('example', EmptyObject::get(), $low))->toBe(PatchResult::Shadowed)
        ->and($guard->provenance()->records)->toHaveCount(1)
        ->and($guard->provenance()->records[0]->overrode)->toBe([]);
});

it('satisfies type: object where an empty array does not', function (): void {
    // Why an array could never have been rescued downstream: this is the validator the example audit
    // runs, on the two values a PHP array cannot tell apart.
    $document = static fn (mixed $example): array => [
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1'],
        'paths' => [],
        'components' => ['schemas' => ['Free' => ['type' => 'object', 'example' => $example]]],
    ];

    $audit = static fn (mixed $example): int => count(
        (new ExampleAudit(ContractIndex::fromArray($document($example))))->run()->findings,
    );

    expect($audit(EmptyObject::get()))->toBe(0)
        ->and($audit([]))->toBe(1);
});
