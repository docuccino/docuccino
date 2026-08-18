<?php

declare(strict_types=1);

use Docuccino\Core\Examples\ExampleRedaction;
use Docuccino\Core\Examples\RecordingStore;
use Docuccino\Laravel\Testing\ApiContract;
use Docuccino\Laravel\Testing\ExampleRecorder;
use Docuccino\Laravel\Testing\UnrecordableRun;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The recorder hangs off the contract-assertion seam, so these run the real assertions against a real
 * build of the workbench and then read what landed on disk. The runner's own parallel markers are
 * cleared and put back for the same reason the coverage suite clears them: every test here would
 * otherwise take the refusal branch.
 */
beforeEach(function (): void {
    $this->runner = [];

    foreach (['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN'] as $variable) {
        $this->runner[$variable] = getenv($variable);
        putenv($variable);
    }

    $this->recordings = sys_get_temp_dir().'/docuccino-recorded-'.getmypid().'-'.bin2hex(random_bytes(6));
});

afterEach(function (): void {
    foreach ($this->runner as $variable => $value) {
        is_string($value) ? putenv($variable.'='.$value) : putenv($variable);
    }

    foreach (glob($this->recordings.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->recordings);

    @unlink(sys_get_temp_dir().'/docuccino-contract-'.getmypid().'.uir.json');
    ApiContract::reset();
});

/**
 * @return array<string, mixed>|null
 */
function recordedFile(string $directory): ?array
{
    $files = (new RecordingStore($directory))->fileNames();

    if ($files === []) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents($directory.'/'.$files[0]), true);

    return is_array($decoded) ? $decoded : null;
}

it('records a response the contract agreed with, filed under the operation id', function (): void {
    workbenchContract();
    ApiContract::record($this->recordings);

    ApiContract::assertions()->assertValidResponse(
        contractResponse('GET', '/api/forms', body: '[{"id":1,"title":"Intake"}]'),
    );

    $recording = recordedFile($this->recordings);

    expect($recording)->not->toBeNull()
        ->and($recording['docuccino'])->toBe('recording/1')
        ->and($recording['operation'])->toStartWith('op:v1:')
        ->and($recording['endpoint'])->toBe('GET /api/forms')
        ->and($recording['responses'])->toBe([[
            'status' => '200',
            'mediaType' => 'application/json',
            'body' => [['id' => 1, 'title' => 'Intake']],
        ]]);

    expect(RecordingStore::fileNameFor($recording['operation']))
        ->toBe((new RecordingStore($this->recordings))->fileNames()[0]);
});

it('records nothing from an exchange that disagreed with the contract', function (): void {
    workbenchContract();
    ApiContract::record($this->recordings);

    expect(fn () => ApiContract::assertions()->assertValidResponse(
        contractResponse('GET', '/api/forms', body: '{"data":[]}'),
    ))->toThrow(AssertionFailedError::class);

    expect(recordedFile($this->recordings))->toBeNull();
});

it('records nothing when only the request half was checked', function (): void {
    workbenchContract();
    ApiContract::record($this->recordings);

    ApiContract::assertions()->assertValidRequest(
        contractResponse('GET', '/api/forms', body: '[{"id":1,"title":"Intake"}]'),
    );

    expect(recordedFile($this->recordings))->toBeNull();
});

it('records nothing from a body that is not JSON', function (string $mediaType, string $body): void {
    workbenchContract();
    ApiContract::record($this->recordings);

    try {
        ApiContract::assertions()->assertValidResponse(
            contractResponse('GET', '/api/forms', body: $body, headers: ['Content-Type' => $mediaType]),
        );
    } catch (AssertionFailedError) {
        // The point is what reached disk, not whether the workbench documents that media type.
    }

    expect(recordedFile($this->recordings))->toBeNull();
})->with([
    'a csv download' => ['text/csv', "id,title\n1,Intake\n"],
    'an image' => ['image/png', 'not really a png'],
    'json that is not json' => ['application/json', '{oops'],
]);

it('records nothing from an empty body', function (): void {
    workbenchContract();
    ApiContract::record($this->recordings);

    try {
        ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: ''));
    } catch (AssertionFailedError) {
        // Same again: an empty body has no example in it either way.
    }

    expect(recordedFile($this->recordings))->toBeNull();
});

it('publishes the response that shows the most of the contract, whichever ran first', function (array $order): void {
    workbenchContract();
    ApiContract::record($this->recordings);

    $bodies = [
        'sparse' => '[]',
        'full' => '[{"id":1,"title":"Intake"}]',
    ];

    foreach ($order as $which) {
        ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: $bodies[$which]));
    }

    expect(recordedFile($this->recordings)['responses'][0]['body'])->toBe([['id' => 1, 'title' => 'Intake']]);
})->with([
    'sparse first' => [['sparse', 'full']],
    'full first' => [['full', 'sparse']],
]);

it('takes the credentials out before anything reaches disk', function (): void {
    workbenchContract();
    ApiContract::record($this->recordings);

    ApiContract::assertions()->assertValidResponse(contractResponse(
        'GET',
        '/api/forms',
        body: '[{"id":1,"title":"Intake","api_token":"live-secret-value"}]',
    ));

    $written = (string) file_get_contents($this->recordings.'/'.(new RecordingStore($this->recordings))->fileNames()[0]);

    expect($written)->not->toContain('live-secret-value')
        ->and($written)->toContain(ExampleRedaction::PLACEHOLDER);
});

it('leaves the committed file byte-identical when only the values moved', function (): void {
    workbenchContract();

    ApiContract::record($this->recordings);
    ApiContract::assertions()->assertValidResponse(
        contractResponse('GET', '/api/forms', body: '[{"id":1,"title":"Intake"}]'),
    );

    $path = $this->recordings.'/'.(new RecordingStore($this->recordings))->fileNames()[0];
    $first = (string) file_get_contents($path);

    // A second run of the same suite against different fixture data: same shape, different values.
    ApiContract::reset();
    workbenchContract();
    ApiContract::record($this->recordings);
    ApiContract::assertions()->assertValidResponse(
        contractResponse('GET', '/api/forms', body: '[{"id":9001,"title":"Something else entirely"}]'),
    );

    expect(file_get_contents($path))->toBe($first)
        ->and($first)->toContain('"title": "Intake"');
});

it('rewrites the committed file when the shape really did move', function (): void {
    workbenchContract();

    ApiContract::record($this->recordings);
    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));

    $path = $this->recordings.'/'.(new RecordingStore($this->recordings))->fileNames()[0];
    $first = (string) file_get_contents($path);

    ApiContract::reset();
    workbenchContract();
    ApiContract::record($this->recordings);
    ApiContract::assertions()->assertValidResponse(
        contractResponse('GET', '/api/forms', body: '[{"id":1,"title":"Intake"}]'),
    );

    expect(file_get_contents($path))->not->toBe($first)
        ->and(file_get_contents($path))->toContain('"title": "Intake"');
});

it('refuses to record from inside a parallel run, the way coverage refuses to answer', function (): void {
    putenv('PARATEST=1');
    putenv('TEST_TOKEN=7');

    expect(fn (): ExampleRecorder => new ExampleRecorder($this->recordings))
        ->toThrow(UnrecordableRun::class, 'cannot be written from inside a parallel test run (worker 7)');

    putenv('TEST_TOKEN');
    putenv('PARATEST');
});

it('says where to put recordings when the document does not', function (): void {
    workbenchContract();
    ApiContract::record();

    expect(fn () => ApiContract::assertions()->assertValidResponse(
        contractResponse('GET', '/api/forms', body: '[]'),
    ))->toThrow(UnrecordableRun::class, "'examples' => ['recordings' => 'docs/recordings']");
});

it('records nothing for an artifact that carries no identities', function (): void {
    workbenchContract();
    ApiContract::record($this->recordings);

    $stripped = json_decode((string) file_get_contents(ApiContract::artifactPath()), true);
    unset($stripped['paths']['/api/forms']['get']['x-docuccino']['id']);
    file_put_contents(ApiContract::artifactPath(), (string) json_encode($stripped));
    ApiContract::using(ApiContract::artifactPath());

    ApiContract::assertions()->assertValidResponse(
        contractResponse('GET', '/api/forms', body: '[{"id":1,"title":"Intake"}]'),
    );

    expect(recordedFile($this->recordings))->toBeNull();
});
