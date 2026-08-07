<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Analysis\RefinedResponse;
use Docuccino\Inference\PhpStan\Analysis\ResponseShapeRefiner;

it('reports a delegation as non-documentable', function (): void {
    $delegation = RefinedResponse::delegation();

    expect($delegation->delegates)->toBeTrue()
        ->and($delegation->isDocumentable())->toBeFalse()
        ->and($delegation->toClassT(ResponseShapeRefiner::CANONICAL_RESPONSE))->toBeNull();
});

it('is documentable when any of payload, status, or content type is recovered', function (RefinedResponse $r, bool $documentable): void {
    expect($r->isDocumentable())->toBe($documentable);
})->with([
    'nothing' => [new RefinedResponse, false],
    'payload only' => [new RefinedResponse(payload: new ArrayShapeT([])), true],
    'status only' => [new RefinedResponse(status: new LiteralT(404)), true],
    'content type only' => [new RefinedResponse(contentType: 'application/problem+json'), true],
]);

it('emits a JsonResponse<payload, status, contentType> ClassT, content type arg only when set', function (): void {
    $type = (new RefinedResponse(new ArrayShapeT([]), new LiteralT(422), null, 'application/problem+json'))
        ->toClassT(ResponseShapeRefiner::CANONICAL_RESPONSE);

    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and($type->typeArgs)->toHaveCount(3)
        ->and($type->typeArgs[1])->toBeInstanceOf(LiteralT::class)
        ->and($type->typeArgs[2])->toEqual(new LiteralT('application/problem+json'));

    $noContentType = (new RefinedResponse(new ArrayShapeT([]), new LiteralT(200)))
        ->toClassT(ResponseShapeRefiner::CANONICAL_RESPONSE);
    expect($noContentType?->typeArgs)->toHaveCount(2);
});

it('places an UnknownT placeholder for an unfolded payload or status (honest, never guessed)', function (): void {
    $type = (new RefinedResponse(payload: null, status: null, contentType: 'application/problem+json'))
        ->toClassT(ResponseShapeRefiner::CANONICAL_RESPONSE);

    expect($type?->typeArgs[0])->toBeInstanceOf(UnknownT::class)
        ->and($type?->typeArgs[1])->toBeInstanceOf(UnknownT::class);
});

it('binds a pass-through status to a literal and clears the parameter marker', function (): void {
    $bound = (new RefinedResponse(status: null, statusParam: 'status'))->withBoundStatus(new LiteralT(422));

    expect($bound->status)->toEqual(new LiteralT(422))
        ->and($bound->statusParam)->toBeNull();
});

it('re-homes a pass-through status onto an outer parameter (transitive binding)', function (): void {
    $rehomed = (new RefinedResponse(status: new LiteralT(500), statusParam: 'inner'))->withStatusParam('outer');

    expect($rehomed->statusParam)->toBe('outer')
        ->and($rehomed->status)->toBeNull(); // a pass-through is not simultaneously a literal
});

it('recognises the bare response class names it should try to enrich', function (): void {
    expect(ResponseShapeRefiner::isResponseFqcn('Illuminate\\Http\\JsonResponse'))->toBeTrue()
        ->and(ResponseShapeRefiner::isResponseFqcn('Illuminate\\Http\\Response'))->toBeTrue()
        ->and(ResponseShapeRefiner::isResponseFqcn('Symfony\\Component\\HttpFoundation\\Response'))->toBeTrue()
        ->and(ResponseShapeRefiner::isResponseFqcn('App\\Models\\User'))->toBeFalse();
});
