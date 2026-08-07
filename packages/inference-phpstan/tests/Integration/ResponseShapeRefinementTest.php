<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
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

    $keys = array_map(static fn (array $f): string => (string) ($f['key'] ?? ''), $type->typeArgs[0]->toArray()['fields'] ?? []);

    return ['status' => $status, 'contentType' => $contentType, 'keys' => $keys, 'deps' => $result['deps']];
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
