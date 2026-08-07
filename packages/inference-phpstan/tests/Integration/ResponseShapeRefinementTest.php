<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine truth for response-shape refinement through project-code helper indirection (the
 * inferred-handler flagship): the ACTUAL PHPStan/Larastan engine follows an invokable renderer's
 * `match (true)` arms into private methods and a static `ProblemResponse::make()`-style helper whose
 * declared bare `JsonResponse` return erased the shape — recovering per-arm status, payload shape and
 * `application/problem+json` content type. This is engine truth a stub cannot stand in for; each row
 * pins one refinement shape the capability must handle.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * @return array{returns: list<array<string, mixed>>, deps: list<string>, diagnostics: list<string>}
 */
function refineInvoke(string $narrowType): array
{
    $analysis = FixtureRunner::analyzeCallable(
        'app/Exceptions/InvoiceProblemRenderer.php',
        'App\\Exceptions\\InvoiceProblemRenderer',
        '__invoke',
        param: 'e',
        narrowType: $narrowType,
    );

    return [
        'returns' => $analysis['returns'],
        'deps' => array_map(static fn (string $p): string => basename($p), $analysis['dependencyFiles']),
        'diagnostics' => array_map(static fn (array $d): string => (string) $d['code'], $analysis['diagnostics']),
    ];
}

/** The recovered `JsonResponse` shape from a single-return refinement: [status, contentType, payload keys]. */
function invokeShape(string $narrowType): array
{
    $result = refineInvoke($narrowType);
    $analysis = ActionAnalysis::fromArray(['returns' => $result['returns']]);
    expect($analysis->returns)->toHaveCount(1);

    $type = $analysis->returns[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class)->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse');

    $statusArg = $type->typeArgs[1] ?? null;
    $status = $statusArg instanceof LiteralT && is_int($statusArg->value) ? $statusArg->value : null;

    $ctArg = $type->typeArgs[2] ?? null;
    $contentType = $ctArg instanceof LiteralT && is_string($ctArg->value) ? $ctArg->value : null;

    $payload = $type->typeArgs[0] ?? null;
    expect($payload)->toBeInstanceOf(ArrayShapeT::class);

    $keys = [];
    $members = [];
    foreach ($payload->fields as $field) {
        $keys[] = (string) $field->key;
        $members[(string) $field->key] = $field->type;
    }

    return ['status' => $status, 'contentType' => $contentType, 'keys' => $keys, 'members' => $members, 'deps' => $result['deps']];
}

it('recovers a TWO-HOP helper chain: __invoke arm → private method → static make() (404)', function (): void {
    $shape = invokeShape('App\\Exceptions\\InvoiceNotFoundException');

    expect($shape['status'])->toBe(404)
        ->and($shape['contentType'])->toBe('application/problem+json')
        ->and($shape['keys'])->toContain('type', 'title', 'status', 'detail')
        // Cache soundness: the helper the shape was recovered through joins the dependency set.
        ->and($shape['deps'])->toContain('ProblemResponse.php');
})->group('fixture');

it('recovers a ONE-HOP helper call: __invoke arm → static make() directly (409)', function (): void {
    $shape = invokeShape('App\\Exceptions\\OrderConflictException');

    expect($shape['status'])->toBe(409)
        ->and($shape['contentType'])->toBe('application/problem+json')
        ->and($shape['keys'])->toContain('type', 'title', 'status', 'detail');
})->group('fixture');

it('recovers the 422 branch WITH the pointer-list errors member via a distinct helper', function (): void {
    $shape = invokeShape('Illuminate\\Validation\\ValidationException');

    expect($shape['status'])->toBe(422)
        ->and($shape['contentType'])->toBe('application/problem+json')
        ->and($shape['keys'])->toContain('type', 'title', 'status', 'detail', 'errors');
})->group('fixture');

it('recovers a DIRECT new JsonResponse(...) return with no hop (429)', function (): void {
    $shape = invokeShape('App\\Exceptions\\RateLimitedException');

    expect($shape['status'])->toBe(429)
        ->and($shape['contentType'])->toBe('application/problem+json')
        ->and($shape['keys'])->toContain('type', 'title', 'status', 'detail');
})->group('fixture');

it('recovers payload + content type but leaves an UNFOLDABLE status permissive', function (): void {
    // renderHttp passes $e->getStatusCode() (non-constant) through the helper's status parameter.
    $shape = invokeShape('Symfony\\Component\\HttpKernel\\Exception\\HttpException');

    expect($shape['status'])->toBeNull() // recovered as UnknownT, not guessed
        ->and($shape['contentType'])->toBe('application/problem+json')
        ->and($shape['keys'])->toContain('type', 'title', 'status', 'detail');
})->group('fixture');

it('does NOT descend into a vendor producer (JsonResponse::fromJsonString) — shape stays bare', function (): void {
    $result = refineInvoke('Illuminate\\Http\\Exceptions\\HttpResponseException');
    $analysis = ActionAnalysis::fromArray(['returns' => $result['returns']]);

    expect($analysis->returns)->toHaveCount(1);
    $type = $analysis->returns[0]->type;
    // A bare JsonResponse (no recovered typeArgs) — the vendor callee was declined, not folded.
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and($type->typeArgs)->toBe([])
        // and no vendor file leaked into the dependency set.
        ->and($result['deps'])->each->not->toContain('JsonResponse.php');
})->group('fixture');

it('reads a per-type null match arm as framework DELEGATION (no response, no fold failure)', function (): void {
    $result = refineInvoke('App\\Exceptions\\InvoiceDelegatedException');
    $analysis = ActionAnalysis::fromArray(['returns' => $result['returns']]);

    // The delegate arm returns null → a void/null return, not a JsonResponse. The mapper defers silently.
    expect($analysis->returns)->toHaveCount(1)
        ->and($analysis->returns[0]->type->kind())->toBeIn(['null', 'void']);
})->group('fixture');

it('maps an unmatched type to the default arm response (500)', function (): void {
    $shape = invokeShape('RuntimeException');

    expect($shape['status'])->toBe(500)
        ->and($shape['contentType'])->toBe('application/problem+json');
})->group('fixture');

it('the broad non-JSON early-out (return null) never shadows the per-type response arms', function (): void {
    // Every specific type still recovers its response despite the `if (! expectsJson) return null;`
    // early-out sitting first in source order — the delegation site is skipped for a response arm.
    foreach (['App\\Exceptions\\InvoiceNotFoundException' => 404, 'App\\Exceptions\\OrderConflictException' => 409] as $type => $status) {
        expect(invokeShape($type)['status'])->toBe($status);
    }
})->group('fixture');

// --- Value-flow: per-arm literals fold into body members through the helper's parameters ---

it('folds a ONE-HOP arm’s per-arm literals into the body members (409: type const + status literal)', function (): void {
    // OrderConflict → make('https://…/conflict', 'Conflict', 409, $e->getMessage()): the call-site literals
    // bind to make()'s $type/$title/$status parameters, so the recovered body carries them as literals —
    // exactly what documents `type` as a `const` and `status` as 409. $detail ($e->getMessage()) does NOT
    // fold and stays widened (honest: never pinned to a value that does not flow to it).
    $members = invokeShape('App\\Exceptions\\OrderConflictException')['members'];

    expect($members['type'])->toEqual(new LiteralT('https://errors.test/problems/conflict'))
        ->and($members['title'])->toEqual(new LiteralT('Conflict'))
        ->and($members['status'])->toEqual(new LiteralT(409))
        ->and($members['detail'])->not->toBeInstanceOf(LiteralT::class);
})->group('fixture');

it('folds a TWO-HOP arm’s per-arm literals two hops out (404: type const + status literal)', function (): void {
    // NotFound → renderNotFound() → make('https://…/not-found', 'Not Found', 404, …): the literals live at
    // the innermost make() call inside renderNotFound and bind there, propagating fully-resolved up to __invoke.
    $members = invokeShape('App\\Exceptions\\InvoiceNotFoundException')['members'];

    expect($members['type'])->toEqual(new LiteralT('https://errors.test/problems/not-found'))
        ->and($members['status'])->toEqual(new LiteralT(404));
})->group('fixture');

it('marks the status body member as a StatusMarkerT when the status does not fold (renderHttp)', function (): void {
    // renderHttp passes $e->getStatusCode() through make()'s $status parameter: the HTTP status stays
    // permissive AND the body `status` member — whose value IS that same parameter — becomes a
    // StatusMarkerT for the response seam to fill with the concrete documented status (never guessed).
    $members = invokeShape('Symfony\\Component\\HttpKernel\\Exception\\HttpException')['members'];

    expect($members['status'])->toBeInstanceOf(StatusMarkerT::class)
        // The non-status literals passed to that arm still fold.
        ->and($members['type'])->toEqual(new LiteralT('about:blank'))
        ->and($members['title'])->toEqual(new LiteralT('HTTP Error'));
})->group('fixture');

it('keeps DIRECTLY-written body literals as literals (422: type/title/status const, dynamic members widened)', function (): void {
    // validation() writes type/title/status as literals directly in the array (no parameter hop), so they
    // are recovered as literals; $detail and the $errors list are dynamic and stay widened.
    $members = invokeShape('Illuminate\\Validation\\ValidationException')['members'];

    expect($members['type'])->toEqual(new LiteralT('https://errors.test/problems/validation'))
        ->and($members['title'])->toEqual(new LiteralT('Unprocessable Entity'))
        ->and($members['status'])->toEqual(new LiteralT(422))
        ->and($members['detail'])->not->toBeInstanceOf(LiteralT::class)
        ->and($members['errors'])->not->toBeInstanceOf(LiteralT::class);
})->group('fixture');

it('folds the direct-constructor arm’s literal body members (429)', function (): void {
    // RateLimited returns new JsonResponse([...all literals...], 429): every member is a literal, status included.
    $members = invokeShape('App\\Exceptions\\RateLimitedException')['members'];

    expect($members['type'])->toEqual(new LiteralT('https://errors.test/problems/rate-limited'))
        ->and($members['status'])->toEqual(new LiteralT(429));
})->group('fixture');

// --- Enum-case accessor folding: a bound case folds ->value / ->name / method accessors (the final hop) ---

it('folds a bound enum case’s accessors into per-case literals + status (403, one hop)', function (): void {
    // InvoiceForbidden → fromProblem(InvoiceProblem::Forbidden, …): the case binds into the helper's
    // $problem parameter and its accessors fold — ->value (type URI), ->name (code), status()/title()
    // (match-method), docsUrl() (plain return) — while classify() (computed) and $detail stay permissive.
    $shape = invokeShape('App\\Exceptions\\InvoiceForbiddenException');
    $members = $shape['members'];

    expect($shape['status'])->toBe(403) // the folded status() drives the HTTP status (not the throw hint)
        ->and($shape['contentType'])->toBe('application/problem+json')
        ->and($members['type'])->toEqual(new LiteralT('https://errors.test/problems/forbidden'))
        ->and($members['code'])->toEqual(new LiteralT('Forbidden'))
        ->and($members['title'])->toEqual(new LiteralT('Forbidden'))
        ->and($members['status'])->toEqual(new LiteralT(403))
        ->and($members['docs'])->toEqual(new LiteralT('https://errors.test/docs'))
        ->and($members['kind'])->not->toBeInstanceOf(LiteralT::class) // computed body — never guessed
        ->and($members['detail'])->not->toBeInstanceOf(LiteralT::class)
        // Cache soundness: the enum whose methods were folded joins the dependency set.
        ->and($shape['deps'])->toContain('InvoiceProblem.php');
})->group('fixture');

it('folds a bound enum case through a TWO-hop re-home (404: missing)', function (): void {
    // InvoiceMissing → renderProblem(InvoiceProblem::NotFound, …) → fromProblem(…): the accessor provenance
    // re-homes through renderProblem's parameter, then folds when the case binds at the outer call.
    $shape = invokeShape('App\\Exceptions\\InvoiceMissingException');
    $members = $shape['members'];

    expect($shape['status'])->toBe(404)
        ->and($members['type'])->toEqual(new LiteralT('https://errors.test/problems/missing'))
        ->and($members['code'])->toEqual(new LiteralT('NotFound'))
        ->and($members['title'])->toEqual(new LiteralT('Not Found'))
        ->and($members['status'])->toEqual(new LiteralT(404));
})->group('fixture');

it('folds a VENDOR enum’s ->value/->name but NEVER analyses its method (400)', function (): void {
    // fromOperator(FilterOperator::EQUAL): ->value and ->name fold via reflection (vendor-safe), but
    // isDynamic() is a vendor method the folder declines to analyse — the member stays permissive.
    $shape = invokeShape('App\\Exceptions\\InvoiceVendorEnumException');
    $members = $shape['members'];

    expect($shape['status'])->toBe(400)
        ->and($members['operator'])->toEqual(new LiteralT('='))
        ->and($members['label'])->toEqual(new LiteralT('EQUAL'))
        ->and($members['dynamic'])->not->toBeInstanceOf(LiteralT::class);
})->group('fixture');

it('folds each case independently + deterministically (memoisation keyed per enum-case+method)', function (): void {
    // The same helper + enum methods reached for two different cases fold to each case's OWN literals
    // (the fold is memoised per enum-case+method, never leaking across cases), and repeating an analysis
    // is byte-identical.
    $forbidden = invokeShape('App\\Exceptions\\InvoiceForbiddenException')['members'];
    $missing = invokeShape('App\\Exceptions\\InvoiceMissingException')['members'];
    $forbiddenAgain = invokeShape('App\\Exceptions\\InvoiceForbiddenException')['members'];

    expect($forbidden['status'])->toEqual(new LiteralT(403))
        ->and($missing['status'])->toEqual(new LiteralT(404))
        ->and($forbidden['title'])->toEqual(new LiteralT('Forbidden'))
        ->and($missing['title'])->toEqual(new LiteralT('Not Found'))
        ->and($forbiddenAgain)->toEqual($forbidden);
})->group('fixture');
