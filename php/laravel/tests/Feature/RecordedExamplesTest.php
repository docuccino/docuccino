<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Examples\ExampleRecording;
use Docuccino\Core\Examples\RecordedExample;
use Docuccino\Core\Examples\RecordingStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;

/**
 * The consuming half, through a real build of the workbench: a committed file becomes a documented
 * example, the artifact does not churn when a re-recording only moved the values, and the build goes on
 * executing nothing at all.
 */
beforeEach(function (): void {
    $this->recordings = base_path('docs/recordings-'.getmypid().'-'.bin2hex(random_bytes(6)));
    mkdir($this->recordings, 0777, true);
});

afterEach(function (): void {
    foreach (glob($this->recordings.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->recordings);
});

/**
 * @return array<string, mixed>
 */
function recordedDocument(string $recordings): array
{
    return stubDocumentArray(static function (array $raw) use ($recordings): array {
        $raw['examples'] = ['recordings' => $recordings];

        return $raw;
    });
}

function formsOperationId(): string
{
    $id = stubDocumentArray()['paths']['/api/forms']['get']['x-docuccino']['id'] ?? null;

    expect($id)->toBeString();

    /** @var string $id */
    return $id;
}

function writeFormsRecording(string $recordings, mixed $body, string $status = '200', ?string $operationId = null): void
{
    (new RecordingStore($recordings))->put(ExampleRecording::of(
        $operationId ?? formsOperationId(),
        'GET /api/forms',
        [RecordedExample::of($status, 'application/json', $body)],
    ));
}

/**
 * @return list<string>
 */
function recordedDiagnosticCodes(string $recordings): array
{
    bindStubEngine();

    $result = generateDocument(static function (array $raw) use ($recordings): array {
        $raw['examples'] = ['recordings' => $recordings];

        return $raw;
    });

    return array_values(array_map(
        static fn (Diagnostic $d): string => $d->code,
        array_filter($result->diagnostics, static fn (Diagnostic $d): bool => str_starts_with($d->code, 'examples.')),
    ));
}

it('publishes a recorded body as the example beside the documented schema', function (): void {
    writeFormsRecording($this->recordings, ['data' => [['id' => 1, 'title' => 'Intake']]]);

    $media = recordedDocument($this->recordings)['paths']['/api/forms']['get']['responses']['200']['content']['application/json'];

    expect($media['example'])->toBe(['data' => [['id' => 1, 'title' => 'Intake']]])
        ->and($media)->toHaveKey('schema');
});

it('publishes nothing when the document names no recordings directory', function (): void {
    writeFormsRecording($this->recordings, ['data' => []]);

    $media = stubDocumentArray()['paths']['/api/forms']['get']['responses']['200']['content']['application/json'];

    expect($media)->not->toHaveKey('example');
});

it('emits the same bytes when a re-recording only moved the values', function (): void {
    writeFormsRecording($this->recordings, ['data' => [['id' => 1, 'title' => 'Intake']]]);
    bindStubEngine();
    $first = (new UirEmitter)->emit(generateDocument(fn (array $raw): array => $raw + ['examples' => ['recordings' => $this->recordings]])->document);

    // What a second run of the same suite writes: the recorder keeps the committed body while its
    // shape is unchanged, so this is a no-op on the file and therefore a no-op on the artifact.
    $store = new RecordingStore($this->recordings);
    $recording = $store->read(formsOperationId());
    expect($recording)->not->toBeNull();
    $store->put($recording->with(RecordedExample::of('200', 'application/json', ['data' => [['id' => 9001, 'title' => 'Later']]])));

    bindStubEngine();
    $second = (new UirEmitter)->emit(generateDocument(fn (array $raw): array => $raw + ['examples' => ['recordings' => $this->recordings]])->document);

    expect($second)->toBe($first)
        ->and($first)->toContain('"title": "Intake"');
});

it('emits the same bytes twice from the same recording', function (): void {
    writeFormsRecording($this->recordings, ['data' => [['id' => 1, 'title' => 'Intake']]]);

    bindStubEngine();
    $first = (new UirEmitter)->emit(generateDocument(fn (array $raw): array => $raw + ['examples' => ['recordings' => $this->recordings]])->document);
    bindStubEngine();
    $second = (new UirEmitter)->emit(generateDocument(fn (array $raw): array => $raw + ['examples' => ['recordings' => $this->recordings]])->document);

    expect($second)->toBe($first);
});

it('opens no database and dispatches no route while it reads one', function (): void {
    writeFormsRecording($this->recordings, ['data' => [['id' => 1, 'title' => 'Intake']]]);

    $ran = [];
    Event::listen(QueryExecuted::class, function () use (&$ran): void {
        $ran[] = 'query';
    });
    Event::listen(RouteMatched::class, function () use (&$ran): void {
        $ran[] = 'route';
    });

    // Any query at all would have to go through a connection that cannot be opened.
    config()->set('database.default', 'docuccino-nonexistent');

    $media = recordedDocument($this->recordings)['paths']['/api/forms']['get']['responses']['200']['content']['application/json'];

    expect($media['example'])->toBe(['data' => [['id' => 1, 'title' => 'Intake']]])
        ->and($ran)->toBe([]);
});

it('reports a recording for an operation the document no longer has', function (): void {
    writeFormsRecording($this->recordings, ['data' => []], operationId: 'op:v1:zzzzzzzz12345678');

    expect(recordedDiagnosticCodes($this->recordings))->toBe(['examples.recording-orphaned']);
});

it('reports, and refuses to publish, a recording that still holds a credential', function (): void {
    writeFormsRecording($this->recordings, ['data' => [], 'api_key' => 'live-secret-value']);

    $media = recordedDocument($this->recordings)['paths']['/api/forms']['get']['responses']['200']['content']['application/json'];

    expect($media)->not->toHaveKey('example')
        ->and(recordedDiagnosticCodes($this->recordings))->toBe(['examples.recording-unsafe']);
});

it('says a configured directory holds nothing yet', function (): void {
    expect(recordedDiagnosticCodes($this->recordings))->toBe(['examples.recordings-empty']);
});

it('reports the same thing on a warm build as on a cold one, and rebuilds when the recording changes', function (): void {
    writeFormsRecording($this->recordings, ['data' => [['id' => 1, 'title' => 'Intake']]]);

    $dir = fragmentCacheDir('recordings');

    try {
        $cold = recordedDocument($this->recordings);
        expect(glob($dir.'/*.json') ?: [])->not->toBeEmpty();

        $warm = recordedDocument($this->recordings);
        expect($warm)->toBe($cold);

        // Editing the committed file has to reach the document, or a re-recording would land warm.
        writeFormsRecording($this->recordings, ['data' => [['id' => 2, 'title' => 'Rewritten']]]);
        $rebuilt = recordedDocument($this->recordings);

        expect($rebuilt['paths']['/api/forms']['get']['responses']['200']['content']['application/json']['example'])
            ->toBe(['data' => [['id' => 2, 'title' => 'Rewritten']]]);
    } finally {
        removeFragmentCacheDir($dir);
    }
});

it('publishes a recording made before the file existed once it does', function (): void {
    $dir = fragmentCacheDir('recordings');

    try {
        $cold = recordedDocument($this->recordings);
        expect($cold['paths']['/api/forms']['get']['responses']['200']['content']['application/json'])
            ->not->toHaveKey('example');

        writeFormsRecording($this->recordings, ['data' => [['id' => 1, 'title' => 'Intake']]]);

        expect(recordedDocument($this->recordings)['paths']['/api/forms']['get']['responses']['200']['content']['application/json']['example'])
            ->toBe(['data' => [['id' => 1, 'title' => 'Intake']]]);
    } finally {
        removeFragmentCacheDir($dir);
    }
});
