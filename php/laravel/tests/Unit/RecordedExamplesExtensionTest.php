<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Examples\ExampleRecording;
use Docuccino\Core\Examples\RecordedExample;
use Docuccino\Core\Examples\RecordingStore;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Extensions\RecordedExamplesExtension;

/**
 * Where a recorded example sits in the precedence ladder, and what it declines to do.
 *
 * These build the draft and the context by hand because the question is precedence rather than
 * recovery; `RecordedExamplesTest` asks the same question of a real build with real attributes on it.
 */
const RECORDED_OPERATION = 'op:v1:abcdefgh12345678';

function recordedExamplesBase(): string
{
    $base = sys_get_temp_dir().'/docuccino-recorded-ext-'.getmypid().'-'.bin2hex(random_bytes(6));
    mkdir($base.'/docs/recordings', 0777, true);

    return $base;
}

function recordedContext(string $base, ?string $operationId = RECORDED_OPERATION): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], '/api/invoices'),
        actionRef: new ActionRef('app/Http/Controllers/InvoiceController.php', 'App\\InvoiceController', 'index'),
        attributes: new AttributeSet,
        engine: new NullTypeEngine,
        document: new DocumentConfig(
            key: 'default',
            info: ['title' => 'T', 'version' => '1'],
            raw: ['examples' => ['recordings' => 'docs/recordings']],
        ),
        operationId: $operationId,
    );
}

function recordedDraft(string $status = '200', string $mediaType = 'application/json'): OperationDraft
{
    $operation = new OperationDraft;
    $operation->response($status)->content($mediaType)->set('type', 'object', Contribution::inference());

    return $operation;
}

function recordInvoice(string $base, mixed $body, string $status = '200', string $mediaType = 'application/json'): void
{
    (new RecordingStore($base.'/docs/recordings'))->put(ExampleRecording::of(
        RECORDED_OPERATION,
        'GET /api/invoices',
        [RecordedExample::of($status, $mediaType, $body)],
    ));
}

/**
 * @return array<string, mixed>
 */
function recordedResponse(OperationDraft $operation, string $status = '200'): array
{
    return $operation->response($status)->freeze()->toArray();
}

it('publishes the recorded body as the media type\'s example', function (): void {
    $base = recordedExamplesBase();
    recordInvoice($base, ['id' => 1, 'total' => 10]);

    $operation = recordedDraft();
    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    expect(recordedResponse($operation)['content']['application/json']['example'])->toBe(['id' => 1, 'total' => 10]);
});

it('keeps the file in the fragment key whether or not it is there yet', function (): void {
    $base = recordedExamplesBase();
    $context = recordedContext($base);

    (new RecordedExamplesExtension($base))->handle(recordedDraft(), $context);

    expect($context->dependencies()->files())
        ->toBe([$base.'/docs/recordings/op-v1-abcdefgh12345678.json']);
});

it('steps aside for an example an author declared', function (array $named, mixed $singular, array $expected): void {
    $base = recordedExamplesBase();
    recordInvoice($base, ['id' => 1]);

    $operation = recordedDraft();
    // What `#[Example]` writes, and it writes it in Finalize — after this extension has run. The
    // declaration wins at freeze rather than by ordering, which is why the recording is attached anyway.
    $operation->response('200')->declareExamples('application/json', $named, $singular);

    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    $content = recordedResponse($operation)['content']['application/json'];

    // Exactly one of the two members, holding exactly what the author wrote: a recording neither
    // displaces an authored example nor joins it under a name the author never chose.
    expect(array_diff_key($content, ['schema' => true]))->toBe($expected);
})->with([
    'a singular one' => [[], ['id' => 'authored'], ['example' => ['id' => 'authored']]],
    'a named map' => [
        ['empty' => ['value' => ['id' => 0]]],
        null,
        ['examples' => ['empty' => ['value' => ['id' => 0]]]],
    ],
    'both, where the map wins' => [
        ['empty' => ['value' => ['id' => 0]]],
        ['id' => 'authored'],
        ['examples' => ['empty' => ['value' => ['id' => 0]]]],
    ],
]);

it('leaves the illustration another integration got there first with', function (): void {
    $base = recordedExamplesBase();
    recordInvoice($base, ['id' => 1]);

    $operation = recordedDraft();
    // The built-in error tiers attach the literals they folded, in an earlier phase.
    $operation->response('200')->setExample('application/json', ['id' => 'folded']);

    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    expect(recordedResponse($operation)['content']['application/json']['example'])->toBe(['id' => 'folded']);
});

it('publishes over an example nothing but the schema carried', function (): void {
    $base = recordedExamplesBase();
    recordInvoice($base, ['id' => 1]);

    $operation = recordedDraft();
    $operation->response('200')->content('application/json')
        ->set('example', ['id' => 'inferred'], Contribution::inference());

    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    // Different slots: an example INSIDE the schema is the schema's own, and the media type beside it
    // still has none of its own until something puts one there.
    expect(recordedResponse($operation)['content']['application/json']['example'])->toBe(['id' => 1]);
});

it('does not publish an example for something the document does not document', function (string $status, string $mediaType): void {
    $base = recordedExamplesBase();
    recordInvoice($base, ['id' => 1], $status, $mediaType);

    $operation = recordedDraft();
    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    expect(recordedResponse($operation)['content']['application/json'] ?? [])->not->toHaveKey('example');
})->with([
    'a status nothing documents' => ['418', 'application/json'],
    'a media type nothing documents' => ['200', 'application/xml'],
]);

it('refuses to publish a committed body that still holds a credential', function (): void {
    $base = recordedExamplesBase();
    recordInvoice($base, ['id' => 1, 'api_key' => 'live-secret-value']);

    $operation = recordedDraft();
    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    expect(recordedResponse($operation)['content']['application/json'])->not->toHaveKey('example');
});

it('publishes nothing at all when there is nothing to publish', function (callable $arrange): void {
    $base = recordedExamplesBase();
    $context = $arrange($base);

    $operation = recordedDraft();
    (new RecordedExamplesExtension($base))->handle($operation, $context);

    expect(recordedResponse($operation)['content']['application/json'])->not->toHaveKey('example');
})->with([
    'no recording' => [fn (string $base): RouteContext => recordedContext($base)],
    'no operation id' => [function (string $base): RouteContext {
        recordInvoice($base, ['id' => 1]);

        return recordedContext($base, null);
    }],
    'a malformed recording' => [function (string $base): RouteContext {
        file_put_contents($base.'/docs/recordings/op-v1-abcdefgh12345678.json', '{oops');

        return recordedContext($base);
    }],
    'a document that names no recordings' => [fn (string $base): RouteContext => new RouteContext(
        route: new RouteDescriptor(['GET'], '/api/invoices'),
        actionRef: new ActionRef('x.php', 'X', 'index'),
        attributes: new AttributeSet,
        engine: new NullTypeEngine,
        document: new DocumentConfig(key: 'default', info: ['title' => 'T', 'version' => '1']),
        operationId: RECORDED_OPERATION,
    )],
]);
