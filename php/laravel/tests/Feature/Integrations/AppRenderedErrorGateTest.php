<?php

declare(strict_types=1);

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Exceptions\DefaultExceptionToResponse;
use Docuccino\Laravel\Integrations\FrameworkErrors\FrameworkErrorsExceptionToResponse;
use Docuccino\Laravel\Integrations\InferredHandler\InferredHandlerExceptionToResponse;
use Docuccino\Laravel\Integrations\ProblemDetails\ProblemDetailsExceptionToResponse;
use Docuccino\Laravel\Integrations\Support\AppRenderedErrors;
use Docuccino\Laravel\Tests\Fixtures\InferredHandler\ProbeRejection;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Two tiers publish a body that is the FRAMEWORK's rather than the application's — the framework-defaults
 * shapes and the terminal fallback's `{message}` — and both stand aside from the BODY (never the status)
 * where the build watched the application's own handler render the exception and could not read what it
 * rendered it to.
 *
 * The gate is what this file is about, in both directions and over both tiers, because a gate that never
 * closes publishes a shape the server does not send and a gate that never opens strips the error contract
 * off every application that has no custom handler at all — which is the population those tiers exist for.
 */
function gateContext(string $errorResponses = 'default', ?TypeEngine $engine = null): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/probe'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: $engine ?? new NullTypeEngine,
        document: new DocumentConfig('default', [], errorResponses: $errorResponses),
    );
}

function gateThrow(string $fqcn, ?int $status = null): ThrownException
{
    return new ThrownException($fqcn, $status, [], ThrowConfidence::Certain, ThrowDisposition::Signal);
}

/**
 * Both tiers that speak for the framework, with an exception each reaches: the framework-defaults tier
 * answers for a mapped exception, and the fallback for one no table knows. Asserting them as one dataset
 * is what stops the two halves of the rule from being covered separately and diverging in the middle.
 *
 * @return array<string, array{0: ExceptionToResponse, 1: ThrownException, 2: string}>
 */
function gatedTiers(): array
{
    return [
        'framework-defaults' => [new FrameworkErrorsExceptionToResponse, gateThrow(ModelNotFoundException::class), '404'],
        'terminal fallback' => [new DefaultExceptionToResponse, gateThrow(ProbeRejection::class), '500'],
    ];
}

it('publishes the framework body where nothing says the application replaced it', function (ExceptionToResponse $tier, ThrownException $throw, string $status): void {
    // The gate OPEN. An application with no custom handler — or one whose renderer hands the throwable
    // back to the framework — has exactly this context: no note, so the framework's own shape stands, and
    // it is stated here in full rather than probed for a key, because this is the contract that must not
    // move for the population these tiers exist for.
    $draft = $tier->toResponse($throw, gateContext(), new ComponentRegistry);

    expect($draft?->status)->toBe($status);

    $schema = $draft?->freeze()->content['application/json']['schema'] ?? [];
    expect($schema['type'] ?? null)->toBe('object')
        ->and($schema['properties'] ?? null)->toBe(['message' => ['type' => 'string']]);
})->with(gatedTiers());

it('states the status and nothing else where the application demonstrably renders the exception', function (ExceptionToResponse $tier, ThrownException $throw, string $status): void {
    // The gate CLOSED. The status is the framework's own classification and stays; the body is the one
    // thing the renderer replaced, so it goes unsaid rather than being asserted over code that refutes it.
    $context = gateContext();
    AppRenderedErrors::record($context, $throw->exceptionFqcn, 'App\\Exceptions\\Handler::render');

    $draft = $tier->toResponse($throw, $context, new ComponentRegistry);
    $frozen = $draft?->freeze()->toArray() ?? [];

    expect($draft?->status)->toBe($status)
        // `description` present is what proves the response is really there and only its body was withheld.
        ->and($frozen)->toHaveKey('description')
        ->and($frozen)->not->toHaveKey('content')
        // Nor a shared component name for a body nobody published — two errors would then meet on one name.
        ->and($draft?->componentClaim())->toBeNull();
})->with(gatedTiers());

it('keys the note by the exact exception, so one throw’s renderer never silences another’s', function (): void {
    $context = gateContext();
    AppRenderedErrors::record($context, ProbeRejection::class, 'App\\Exceptions\\Handler::render');

    expect(AppRenderedErrors::includes($context, ProbeRejection::class))->toBeTrue()
        ->and(AppRenderedErrors::includes($context, ModelNotFoundException::class))->toBeFalse();

    // The framework tier answering for a DIFFERENT exception on the same route keeps its body.
    $draft = (new FrameworkErrorsExceptionToResponse)->toResponse(gateThrow(ModelNotFoundException::class), $context, new ComponentRegistry);
    expect($draft?->freeze()->content['application/json']['schema']['properties'] ?? null)
        ->toBe(['message' => ['type' => 'string']]);
});

it('never gates the active preset, whose body is the application’s own declared contract', function (): void {
    // The preset is not the framework speaking: the document opted into it, so it describes the shape the
    // application publishes and a renderer nobody could read does not refute it.
    $context = gateContext('problem-details');
    AppRenderedErrors::record($context, ModelNotFoundException::class, 'App\\Exceptions\\Handler::render');

    $components = new ComponentRegistry;
    $draft = (new ProblemDetailsExceptionToResponse)->toResponse(gateThrow(ModelNotFoundException::class), $context, $components);

    expect($draft?->freeze()->ref)->toBe('#/components/responses/ProblemNotFound')
        ->and($components->responses()['ProblemNotFound']['content'] ?? [])->toHaveKey('application/problem+json');
});

/**
 * The write side, through the real tier: which renderers count as the application answering for an
 * exception. Only one that RETURNED something and did not hand the type back to the framework — anything
 * else leaves the framework default the best answer anyone has.
 */
it('records the note only for a renderer that returned something of its own', function (ActionAnalysis $analysis, bool $recorded): void {
    $symbol = registerRenderCallback(
        static fn (ProbeRejection $e) => response()->json(['title' => 'Nope'], 400),
        ProbeRejection::class,
    );

    $context = gateContext('default', WorkbenchEngine::make([$symbol => $analysis]));
    app(InferredHandlerExceptionToResponse::class)->toResponse(gateThrow(ProbeRejection::class), $context, new ComponentRegistry);

    expect(AppRenderedErrors::includes($context, ProbeRejection::class))->toBe($recorded);
})->with([
    // A response of its own the build could not read: the application has demonstrably replaced the shape.
    'an unreadable response' => [new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\Response'), new SourceLocation(''))]), true],
    // `return null` / a void arm: the renderer hands the throwable back, so the framework really does
    // render it and its default is the truth.
    'a null delegation' => [new ActionAnalysis(returns: [new ReturnSite(new NullT, new SourceLocation(''))]), false],
    'a void delegation' => [new ActionAnalysis(returns: [new ReturnSite(new VoidT, new SourceLocation(''))]), false],
    // Nothing recovered at all refutes nothing — the gate keys on a renderer that says otherwise, never on
    // a fold that failed.
    'nothing recovered' => [new ActionAnalysis, false],
]);

it('leaves an application with no handler at all untouched, through the whole pipeline', function (): void {
    // The population the framework tier exists for, byte for byte and end to end: no render callback is
    // registered, so nothing is ever recorded and the stock body is published exactly as before.
    bindStubEngine();

    $document = generateDocument()->document->toArray();
    $response = $document['paths']['/api/forms/{form}']['get']['responses']['404'] ?? [];
    $schema = resolveSchema($document, resolveResponse($document, $response)['content']['application/json']['schema'] ?? []);

    expect($schema['type'] ?? null)->toBe('object')
        ->and($schema['properties'] ?? null)->toBe(['message' => ['type' => 'string']])
        ->and($schema['required'] ?? null)->toBe(['message']);
});

/** A renderer for the whole route set, so the pipeline tests below differ only in what it renders TO. */
function renderedProbeDocument(ReturnSite ...$returns): array
{
    $symbol = registerRenderCallback(
        static fn (ProbeRejection $e) => response()->json(['title' => 'Nope'], 400),
        ProbeRejection::class,
    );

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        [$symbol => new ActionAnalysis(returns: $returns)],
        analysisOverrides: [
            'Workbench\\App\\Http\\Controllers\\FormController::show' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Workbench\\App\\Data\\FormData'), new SourceLocation(''))],
                throws: [gateThrow(ProbeRejection::class)],
            ),
        ],
    ));

    return generateDocument()->document->toArray();
}

it('withholds the fallback body for an unmapped exception the application renders itself', function (): void {
    // The sibling of the framework-defaults case: an exception no table knows reaches the terminal tier,
    // and its generic `{message}` is the framework's shape just the same.
    $document = renderedProbeDocument(new ReturnSite(new ClassT('Illuminate\\Http\\Response'), new SourceLocation('')));
    $responses = $document['paths']['/api/forms/{form}']['get']['responses'];

    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['500']['x-docuccino']['provenance'] ?? []);
    $response = resolveResponse($document, $responses['500']);

    expect($producers)->toContain('fallback')
        ->and($response['description'] ?? null)->toBe('Internal Server Error')
        ->and($response)->not->toHaveKey('content');
});

it('keeps the fallback body when that same renderer delegates to the framework', function (): void {
    // The gate open on the same route set, so the only thing that moved is what the renderer returns.
    $document = renderedProbeDocument(new ReturnSite(new NullT, new SourceLocation('')));
    $responses = $document['paths']['/api/forms/{form}']['get']['responses'];
    $schema = resolveSchema($document, resolveResponse($document, $responses['500'])['content']['application/json']['schema'] ?? []);

    expect($schema['properties'] ?? null)->toBe(['message' => ['type' => 'string']]);
});

it('gives the framework tier its stock 404 back when the renderer only delegates', function (): void {
    $symbol = registerRenderCallback(
        static fn (ModelNotFoundException $e) => null,
        ModelNotFoundException::class,
    );
    app()->instance(TypeEngine::class, WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(new VoidT, new SourceLocation(''))]),
    ]));

    $document = generateDocument()->document->toArray();
    $response = $document['paths']['/api/forms/{form}']['get']['responses']['404'] ?? [];
    $schema = resolveSchema($document, resolveResponse($document, $response)['content']['application/json']['schema'] ?? []);

    expect($schema['properties'] ?? null)->toBe(['message' => ['type' => 'string']]);
});

/** A guard the gate cannot be widened past: `ResponseDraft` still refuses content on a bodyless status. */
it('is about the body alone — the status a tier classifies never moves', function (): void {
    $context = gateContext();
    AppRenderedErrors::record($context, ModelNotFoundException::class, 'App\\Exceptions\\Handler::render');

    $gated = (new FrameworkErrorsExceptionToResponse)->toResponse(gateThrow(ModelNotFoundException::class), $context, new ComponentRegistry);
    $open = (new FrameworkErrorsExceptionToResponse)->toResponse(gateThrow(ModelNotFoundException::class), gateContext(), new ComponentRegistry);

    expect($gated)->toBeInstanceOf(ResponseDraft::class)
        ->and($gated?->status)->toBe($open?->status)
        ->and($gated?->freeze()->description)->toBe($open?->freeze()->description);
});

it('records nothing where the tier ANSWERED, since no tier behind is asked about it', function (): void {
    // The gate exists to stand the tiers behind down, and they are only reached where this tier declines
    // — `RouteContext::mapThrow()` stops at the first answer. A renderer whose media type folded and whose
    // body did not is answered for HERE, so a note about it could be read by nothing; recording one would
    // be state written for a reader that no longer exists. The paired row below is the same renderer with
    // no media type to keep, which still declines and still owes the note.
    $symbol = registerRenderCallback(
        static fn (ProbeRejection $e) => response()->json(['dynamic' => true], 400, ['Content-Type' => 'application/problem+json']),
        ProbeRejection::class,
    );

    $answered = gateContext('default', WorkbenchEngine::make([$symbol => new ActionAnalysis(returns: [new ReturnSite(
        new ClassT('Illuminate\\Http\\JsonResponse', [new UnknownT('payload not folded'), new UnknownT('status not folded'), new LiteralT('application/problem+json')]),
        new SourceLocation(''),
    )])]));
    $declined = gateContext('default', WorkbenchEngine::make([$symbol => new ActionAnalysis(returns: [new ReturnSite(
        new ClassT('Illuminate\\Http\\JsonResponse', [new UnknownT('payload not folded'), new UnknownT('status not folded')]),
        new SourceLocation(''),
    )])]));

    $tier = app(InferredHandlerExceptionToResponse::class);

    expect($tier->toResponse(gateThrow(ProbeRejection::class), $answered, new ComponentRegistry))->not->toBeNull()
        ->and(AppRenderedErrors::includes($answered, ProbeRejection::class))->toBeFalse()
        ->and($tier->toResponse(gateThrow(ProbeRejection::class), $declined, new ComponentRegistry))->toBeNull()
        ->and(AppRenderedErrors::includes($declined, ProbeRejection::class))->toBeTrue();
});
