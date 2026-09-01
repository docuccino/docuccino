<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * The throw-analysis scorecard against the real engine: abort status folding, registry
 * enrichment + rescue, bounded descent, `@throws` trust, catch subtraction, and
 * exception identity by (fqcn, status).
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * @return array<string, mixed>
 */
function throwsAnalysis(string $method): array
{
    return FixtureRunner::analyze(
        'app/Http/Controllers/ThrowsController.php',
        'App\\Http\\Controllers\\ThrowsController',
        $method,
    );
}

/**
 * @return list<string> signal-disposition exceptions as "ShortName@status"
 */
function signalThrows(string $method): array
{
    $analysis = throwsAnalysis($method);

    $out = [];
    /** @var list<array<string, mixed>> $throws */
    $throws = $analysis['throws'];
    foreach ($throws as $throw) {
        if (($throw['disposition'] ?? null) !== 'signal') {
            continue;
        }
        $fqcn = (string) $throw['exceptionFqcn'];
        $pos = strrpos($fqcn, '\\');
        $short = $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
        $out[] = $short.'@'.($throw['httpStatusHint'] ?? 'null');
    }
    sort($out);

    return $out;
}

dataset('throw cases', [
    'abort + abort_if, both statuses folded' => ['abortAction', ['HttpException@403', 'HttpException@404']],
    // The same two calls with the status named rather than counted. PHPStan hands throw points the
    // NORMALIZED call, so a named argument already sits in the position the registry indexes — pinned
    // here because the day that stops being true, both statuses vanish without a word.
    'abort + abort_if, statuses named' => ['namedAbortAction', ['HttpException@418', 'HttpException@451']],
    'authorize → 403' => ['authorizeAction', ['AuthorizationException@403']],
    'static findOrFail rescued → 404' => ['findOrFailAction', ['ModelNotFoundException@404']],
    'inline validate → 422' => ['validateAction', ['ValidationException@422']],
    '2-deep descent, no @throws' => ['deepUndeclared', ['OutOfStockException@500', 'RuntimeException@500']],
    '@throws trusted, deeper hidden' => ['deepDeclared', ['OutOfStockException@500']],
    'vendor any-throwable = no API error' => ['anyThrowableNoise', []],
    'caught subtracted, escaping surfaced' => ['tryCatch', ['RuntimeException@500']],
    // The registry is keyed on a bare method name, so an app's own validate() is exactly where a guess
    // could overrule a truth: the callee is project code we read, so its own exception stands and no
    // ValidationException/422 is invented for it.
    "the app's own validate() keeps its own exception" => ['projectValidate', ['OutOfStockException@500']],
    // An exception that IS an HTTP status states it in its own constructor, where no name-keyed table can
    // see it. Each row is one of the shapes an application writes that in, and 500 — the answer a lookup
    // miss used to give all three — would be a failure the server does not have.
    'a status pinned through a private constructor default' => ['pinnedHttpStatus', ['ExportRejectedException@422']],
    'a status pinned as a literal two classes up' => ['inheritedHttpStatus', ['PortalUnavailableException@503']],
    'a status written at the throw, no constructor of its own' => ['httpStatusAtThrowSite', ['ExportLockedException@423']],
    // The same default behind a PUBLIC constructor is a guess: the degraded answer is no status at all,
    // which is a different claim from 500 and is what the diagnostic below is raised for.
    'a public constructor default is not a pin' => ['unreadHttpStatus', ['ExportBlockedException@null']],
]);

it('surfaces exactly the expected API errors', function (string $method, array $expected): void {
    sort($expected);

    expect(signalThrows($method))->toBe($expected);
})->with('throw cases')->group('fixture');

/**
 * @return list<string> the messages of every unread-HTTP-status notice the analysis raised
 */
function unreadStatusDiagnostics(string $method): array
{
    /** @var list<array<string, mixed>> $diagnostics */
    $diagnostics = throwsAnalysis($method)['diagnostics'];

    $out = [];
    foreach ($diagnostics as $diagnostic) {
        if (($diagnostic['code'] ?? null) === 'inference.http-exception-status-unread') {
            $out[] = (string) $diagnostic['message'];
        }
    }

    return $out;
}

it('names the class whose HTTP status it could not read', function (): void {
    $reported = unreadStatusDiagnostics('unreadHttpStatus');

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toContain('App\\Exceptions\\ExportBlockedException');
})->group('fixture');

it('says nothing where the status read', function (string $method): void {
    expect(unreadStatusDiagnostics($method))->toBe([]);
})->with([
    'pinnedHttpStatus',
    'inheritedHttpStatus',
    'httpStatusAtThrowSite',
    // And nothing for a plain domain exception either: it is not an HttpException, so there is no status
    // on it to have failed to read.
    'deepUndeclared',
])->group('fixture');

it('depends on the file the status was written in', function (): void {
    // Fragment-cache soundness: the status now comes out of the exception class, so editing it has to
    // rebuild every route that throws it — including the abstract base, where the next edit may put a
    // constructor that changes the answer.
    /** @var list<string> $files */
    $files = throwsAnalysis('inheritedHttpStatus')['dependencyFiles'];
    $names = array_map(static fn (string $file): string => basename($file), $files);

    expect($names)->toContain('PortalUnavailableException.php')
        ->and($names)->toContain('PortalException.php');
})->group('fixture');
