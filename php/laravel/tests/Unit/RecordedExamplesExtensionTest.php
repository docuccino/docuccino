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
 * recovery: a workbench route would have to grow a `#[Example]` to ask it, which would change what
 * every other suite builds.
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

it('steps aside for an example somebody wrote', function (string $producer): void {
    $base = recordedExamplesBase();
    recordInvoice($base, ['id' => 1]);

    $operation = recordedDraft();
    $operation->response('200')->content('application/json')
        ->set('example', ['id' => 'authored'], Contribution::forProducer($producer));

    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    $content = recordedResponse($operation)['content']['application/json'];

    expect($content)->not->toHaveKey('example')
        ->and($content['schema']['example'])->toBe(['id' => 'authored']);
})->with([
    'a docblock @example' => ['docblock'],
    'an #[Example]' => ['attribute'],
    'an overlay' => ['overlay'],
    'config' => ['config'],
]);

it('publishes over an example nothing but inference put there', function (string $producer): void {
    $base = recordedExamplesBase();
    recordInvoice($base, ['id' => 1]);

    $operation = recordedDraft();
    $operation->response('200')->content('application/json')
        ->set('example', ['id' => 'inferred'], Contribution::forProducer($producer));

    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    expect(recordedResponse($operation)['content']['application/json']['example'])->toBe(['id' => 1]);
})->with([
    'inference' => ['inference'],
    'the terminal fallback' => ['fallback'],
    'another integration' => ['integration:eloquent'],
]);

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
