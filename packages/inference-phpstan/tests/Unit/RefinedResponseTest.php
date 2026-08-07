<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Analysis\RefinedResponse;
use Docuccino\Inference\PhpStan\Analysis\ResponseShapeRefiner;

/** The value type of one member of a shape payload (helper for the binding assertions). */
function memberType(RefinedResponse $r, string $key): mixed
{
    expect($r->payload)->toBeInstanceOf(ArrayShapeT::class);
    foreach ($r->payload->fields as $field) {
        if ((string) $field->key === $key) {
            return $field->type;
        }
    }

    return null;
}

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

it('rewrites the payload and its member→parameter provenance while preserving everything else', function (): void {
    $original = new RefinedResponse(
        payload: new ArrayShapeT([]),
        status: new LiteralT(403),
        contentType: 'application/problem+json',
        payloadParamProvenance: ['status' => 'status', 'type' => 'type'],
    );

    $bound = $original->withPayload(new ArrayShapeT([]), ['type' => 'kind']);

    expect($bound->payloadParamProvenance)->toBe(['type' => 'kind'])
        ->and($bound->status)->toEqual(new LiteralT(403))
        ->and($bound->contentType)->toBe('application/problem+json');

    // The status-only transforms carry the provenance through unchanged.
    expect($original->withBoundStatus(new LiteralT(404))->payloadParamProvenance)->toBe(['status' => 'status', 'type' => 'type'])
        ->and($original->withStatusParam('outer')->payloadParamProvenance)->toBe(['status' => 'status', 'type' => 'type']);
});

it('marks a status-parameter body member as a StatusMarkerT via the constructor factory', function (): void {
    $payload = new ArrayShapeT([
        new ArrayShapeField('type', ScalarT::string()),
        new ArrayShapeField('status', ScalarT::int()),
    ]);

    $refined = RefinedResponse::fromConstructor($payload, null, 'status', 'application/problem+json', ['type' => 'type', 'status' => 'status']);

    // The member whose provenance is the status parameter is marked; the others keep their type.
    expect(memberType($refined, 'status'))->toBeInstanceOf(StatusMarkerT::class)
        ->and(memberType($refined, 'type'))->toEqual(ScalarT::string())
        ->and($refined->statusParam)->toBe('status');

    // With a folded status (no pass-through parameter) nothing is marked.
    $literalStatus = RefinedResponse::fromConstructor($payload, new LiteralT(429), null, null, []);
    expect(memberType($literalStatus, 'status'))->toEqual(ScalarT::int());
});

it('binds a body member to a folded literal, dropping its provenance', function (): void {
    $refined = (new RefinedResponse(
        payload: new ArrayShapeT([new ArrayShapeField('type', ScalarT::string())]),
        payloadParamProvenance: ['type' => 'type'],
    ))->bindMember('type', new LiteralT('https://errors.test/conflict'), null);

    expect(memberType($refined, 'type'))->toEqual(new LiteralT('https://errors.test/conflict'))
        ->and($refined->payloadParamProvenance)->toBe([]);
});

it('re-homes a body member’s provenance onto an outer parameter when the argument is a caller parameter', function (): void {
    $refined = (new RefinedResponse(
        payload: new ArrayShapeT([new ArrayShapeField('type', ScalarT::string())]),
        payloadParamProvenance: ['type' => 'inner'],
    ))->bindMember('type', null, 'outer');

    expect($refined->payloadParamProvenance)->toBe(['type' => 'outer'])
        ->and(memberType($refined, 'type'))->toEqual(ScalarT::string());
});

it('drops provenance and leaves a StatusMarkerT status member intact when the status does not fold', function (): void {
    $refined = (new RefinedResponse(
        payload: new ArrayShapeT([new ArrayShapeField('status', new StatusMarkerT)]),
        payloadParamProvenance: ['status' => 'status'],
    ))->bindMember('status', null, null);

    // Neither a literal nor a caller parameter: provenance drops, the marker survives for the seam to fill.
    expect(memberType($refined, 'status'))->toBeInstanceOf(StatusMarkerT::class)
        ->and($refined->payloadParamProvenance)->toBe([]);
});

it('is a payload no-op for a non-shape body (nothing to mark or bind)', function (): void {
    // fromConstructor must not mark a non-keyed-shape body even with a pass-through status parameter…
    $classPayload = new ClassT('App\\Data\\ErrorData');
    $refined = RefinedResponse::fromConstructor($classPayload, null, 'status', null, ['status' => 'status']);
    expect($refined->payload)->toBe($classPayload)
        ->and($refined->statusParam)->toBe('status');

    // …and bindMember leaves a non-shape body untouched while still dropping the resolved provenance.
    $bound = (new RefinedResponse(payload: $classPayload, payloadParamProvenance: ['status' => 'status']))
        ->bindMember('status', new LiteralT(500), null);
    expect($bound->payload)->toBe($classPayload)
        ->and($bound->payloadParamProvenance)->toBe([]);
});

it('recognises the bare response class names it should try to enrich', function (): void {
    expect(ResponseShapeRefiner::isResponseFqcn('Illuminate\\Http\\JsonResponse'))->toBeTrue()
        ->and(ResponseShapeRefiner::isResponseFqcn('Illuminate\\Http\\Response'))->toBeTrue()
        ->and(ResponseShapeRefiner::isResponseFqcn('Symfony\\Component\\HttpFoundation\\Response'))->toBeTrue()
        ->and(ResponseShapeRefiner::isResponseFqcn('App\\Models\\User'))->toBeFalse();
});
