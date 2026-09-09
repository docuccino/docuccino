<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\RawTextDiagnosticExtension;
use Illuminate\Routing\Router;

/*
 * Whether a name the application chose reaches the published document as text or as a control sequence,
 * asked of a producer that does nothing to help. {@see RawTextDiagnosticExtension} is written the way a
 * producer that has never heard of the invariant is written — the name goes straight into the sentence —
 * and `Diagnostic` is what has to make that safe. So these rows say nothing about which producers
 * remembered; they say the answer does not depend on it.
 *
 * The absence rows read the EMITTED bytes rather than the message, because the document is where no
 * render boundary of ours can help: it is written with `JSON_UNESCAPED_UNICODE`, so `json_encode`
 * escapes the ASCII controls and passes a C1 introducer, a direction override and a line separator
 * through whole.
 */
afterEach(function (): void {
    removeFragmentCacheDirs('diagescape');
});

it('makes safe what a producer stated raw, wherever the producer put it', function (): void {
    Docuccino::extend(new RawTextDiagnosticExtension);
    $result = localityBuild(static fn (Router $router) => $router->get('api/zz-raw-text', fn (): array => ['ok' => true]));

    $reported = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $d): bool => str_starts_with($d->code, 'test.raw'),
    ));

    // Stated positively as well as negatively: a row that only checked for absence would pass just as
    // well on a message that had lost the name altogether.
    expect($reported)->toHaveCount(1)
        ->and($reported[0]->code)->toBe('test.raw\\u{202E}edoc')
        ->and($reported[0]->message)->toBe('The name "Evil\\x1B[31m\\u{009B}31m\\u{202E}\\u{2028}\\x0D\\x0AName" was read as written.')
        ->and($reported[0]->help)->toBe("Correct it.\nIt is spelled \"Evil\\x1B[31m\\u{009B}31m\\u{202E}\\u{2028}Name\" today.");
});

it('keeps a help line break as layout, and the route signature as the route answers it', function (): void {
    // A newline is the one control character `help` keeps: a console writer indents each of its lines
    // past anything they could be mistaken for, and `json_encode` escapes a newline whatever else it
    // leaves alone. The signature is left whole for a different reason — it is a KEY, matched against
    // what a live route reports (`explain`) and sorted on, so escaping it would make the diagnostic
    // name a route nothing can find.
    Docuccino::extend(new RawTextDiagnosticExtension);
    $result = localityBuild(static fn (Router $router) => $router->get('api/zz-raw-text', fn (): array => ['ok' => true]));

    $reported = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $d): bool => str_starts_with($d->code, 'test.raw'),
    ));

    expect(substr_count((string) $reported[0]->help, "\n"))->toBe(1)
        ->and($reported[0]->routeSignature)->toBe('GET /api/zz-raw-text');
});

it('publishes no sequence that steers whatever renders the artifact', function (string $hazard): void {
    Docuccino::extend(new RawTextDiagnosticExtension);
    $result = localityBuild(static fn (Router $router) => $router->get('api/zz-raw-text', fn (): array => ['ok' => true]));

    $reported = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $d): bool => str_starts_with($d->code, 'test.raw'),
    ));

    $document = $result->document->toArray();
    $document['x-docuccino']['diagnostics'] = array_map(static fn (Diagnostic $d): array => $d->toArray(), $reported);
    $emitted = (new UirEmitter)->emit(UirDocument::fromArray($document));

    // The positive control the absence needs: the name did reach these bytes, escaped.
    expect($emitted)->toContain('u{202E}')
        ->and($emitted)->not->toContain($hazard);
})->with([
    'ANSI escape' => "\x1B",
    'C1 control sequence introducer' => "\u{009B}",
    'right-to-left override' => "\u{202E}",
    'line separator' => "\u{2028}",
]);

it('says the same thing on a warm build as on a cold one, rather than escaping the escapes again', function (): void {
    // Idempotence is the whole reason this can live at construction: `fromArray()` is how a diagnostic
    // comes back off a warm fragment-cache hit, and it comes back through the constructor. A second
    // escaping layer per rebuild would be invisible to any single build and obvious here.
    Docuccino::extend(new RawTextDiagnosticExtension);
    $routes = static fn (Router $router) => $router->get('api/zz-raw-text', fn (): array => ['ok' => true]);

    fragmentCacheDir('diagescape');
    $coldEngine = null;
    $cold = localityBuild($routes, null, $coldEngine);

    $warmEngine = null;
    $warm = localityBuild($routes, null, $warmEngine);

    // Otherwise the row proves nothing: two cold builds agree whatever the constructor does to the text.
    assert($coldEngine instanceof CountingTypeEngine && $warmEngine instanceof CountingTypeEngine);
    expect($coldEngine->analyzeCount)->toBeGreaterThan(0)
        ->and($warmEngine->analyzeCount)->toBe(0);

    // What a build with nothing cached says — the truth the warm one owes, diagnostics included.
    fragmentCacheDir('diagescape');
    $truth = localityBuild($routes);

    expect([(new UirEmitter)->emit($warm->document), diagnosticRecords($warm->diagnostics)])
        ->toBe([(new UirEmitter)->emit($truth->document), diagnosticRecords($truth->diagnostics)])
        ->and(diagnosticRecords($cold->diagnostics))->toBe(diagnosticRecords($truth->diagnostics));
});
