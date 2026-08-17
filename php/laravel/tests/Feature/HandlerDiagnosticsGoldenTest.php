<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerDeferralLog;
use Docuccino\Laravel\Tests\Fixtures\InferredHandler\PortableCallbackLabels;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Routing\Router;

/**
 * The two diagnostics that name a callback nothing but a file can name — a skipped render callback and a
 * handler that could not fold a response — locked in emitted bytes, because a machine path here is a
 * machine path in the artifact (`x-docuccino.diagnostics`, which is what `--embed-diagnostics` writes).
 * A golden is the only thing that fails on the WHOLE string rather than on the part an assertion thought
 * to look at, so it is what catches the path coming back somewhere else in the sentence.
 *
 * It owns its route set (none) and embeds only these two codes: a per-build diagnostic needs no route to
 * fire, and a golden that also carried an operation, or an unrelated build warning, would churn for
 * reasons that have nothing to do with what it proves.
 */
it('emits the callback diagnostics byte-identical to their committed golden', function (): void {
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable(PortableCallbackLabels::unanalysable());
    app(HandlerDeferralLog::class)->record(PortableCallbackLabels::deferralLabel(), RuntimeException::class);

    $result = localityBuild(static fn (Router $router): null => null);

    $embedded = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $d): bool => str_starts_with($d->code, 'inferred-handler.'),
    ));

    $document = $result->document->toArray();
    $document['x-docuccino']['diagnostics'] = array_map(static fn (Diagnostic $d): array => $d->toArray(), $embedded);

    $emitted = (new UirEmitter)->emit(UirDocument::fromArray($document));

    $path = dirname(__DIR__).'/Fixtures/golden/handler-diagnostics.uir.json';
    if (getenv('DOCUCCINO_UPDATE_GOLDEN') === '1') {
        file_put_contents($path, $emitted);
    }

    expect($emitted)->toBe(file_get_contents($path))
        // What the golden is for, said out loud: neither the app's base path nor the checkout above it
        // may appear anywhere in those bytes, and both labels still name a file and a line.
        ->and($emitted)->not->toContain(base_path())
        ->and($emitted)->not->toContain(dirname(__DIR__, 4))
        ->and($emitted)->toContain('tests/Fixtures/InferredHandler/PortableCallbackLabels.php');
});
