<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\Arazzo\ArazzoEmitter;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Tests\Support\ArazzoSchema;
use Opis\JsonSchema\Helper;

/**
 * The Arazzo emitter, held to the published Arazzo 1.1 schema rather than to a golden alone.
 *
 * The translation under test is IDENTITY → NAME. A workflow step holds the operation's node id, which
 * is what keeps it pointing at the right operation across a rename; Arazzo addresses operations by
 * `operationId`, which is what an OpenAPI consumer has. Everything below is about that resolution and
 * about what happens when it cannot be made.
 */

/**
 * The fixture document, with `$mutate` applied to its decoded array first.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>|null  $mutate
 */
function workflowDocument(?callable $mutate = null): UirDocument
{
    /** @var array<string, mixed> $array */
    $array = json_decode(
        (string) file_get_contents(dirname(__DIR__).'/Fixtures/workflows.uir.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return UirDocument::fromArray($mutate === null ? $array : $mutate($array));
}

/**
 * @return array{0: array<string, mixed>, 1: list<string>}
 */
function emitArazzo(?callable $mutate = null, EmitOptions $options = new EmitOptions): array
{
    $result = (new ArazzoEmitter)->emitWithReport(workflowDocument($mutate), $options);

    /** @var array<string, mixed> $decoded */
    $decoded = $result->output === '' ? [] : json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

    return [$decoded, array_map(static fn (Diagnostic $d): string => $d->code, $result->report->diagnostics)];
}

it('emits a description the published Arazzo schema accepts', function (): void {
    [$description] = emitArazzo();

    $result = ArazzoSchema::validator()->validate(
        Helper::toJSON($description),
        ArazzoSchema::PUBLISHED,
    );

    // Named rather than counted: a reader of the failure needs the position, not a boolean.
    $problem = $result->error() === null ? null : $result->error()->message().' at '.implode('/', $result->error()->data()->path());

    expect($problem)->toBeNull()
        ->and($result->isValid())->toBeTrue();
});

it('addresses each step by the operationId the document publishes, not by the node id', function (): void {
    [$description] = emitArazzo();

    expect(array_column($description['workflows'][0]['steps'], 'operationId'))
        ->toBe(['reserveBasket', 'createPayment']);
});

it('keeps the declared order of the steps', function (): void {
    // A workflow's steps are a sequence the author settled; re-ordering them changes what it does.
    [$description] = emitArazzo();

    expect(array_column($description['workflows'][0]['steps'], 'stepId'))->toBe(['reserve', 'pay']);
});

it('carries what the workflow says about itself', function (): void {
    [$description] = emitArazzo();
    $workflow = $description['workflows'][0];

    expect($workflow['workflowId'])->toBe('checkout')
        ->and($workflow['summary'])->toBe('Take payment for a basket')
        ->and($workflow['inputs'])->toBe(['type' => 'object', 'properties' => ['basketId' => ['type' => 'string']]])
        ->and($workflow['outputs'])->toBe(['receipt' => '$steps.pay.outputs.receiptId']);
});

it('carries the parameters, body and outputs a step threads', function (): void {
    [$description] = emitArazzo();
    [$reserve, $pay] = $description['workflows'][0]['steps'];

    expect($reserve['parameters'])->toBe([['name' => 'basket', 'in' => 'path', 'value' => '$inputs.basketId']])
        ->and($reserve['outputs'])->toBe(['holdId' => '$response.body#/id'])
        ->and($pay['requestBody'])->toBe([
            'contentType' => 'application/json',
            'payload' => ['hold' => '$steps.reserve.outputs.holdId'],
        ]);
});

/*
 * Derived rather than declared: the document already says what a successful call answers with, so a
 * step gets a criterion a runner can actually fail without anybody writing one.
 */
it('checks the status the document documents as the success', function (): void {
    [$description] = emitArazzo();
    [$reserve, $pay] = $description['workflows'][0]['steps'];

    expect($reserve['successCriteria'])->toBe([['condition' => '$statusCode == 201']])
        ->and($pay['successCriteria'])->toBe([['condition' => '$statusCode == 200']]);
});

it('asserts no status where the operation documents more than one success', function (): void {
    // Two 2xx responses is the operation saying it has more than one outcome. Picking between them
    // would fail a workflow that worked, which is worse than asserting nothing.
    [$description] = emitArazzo(function (array $document): array {
        $document['paths']['/api/receipts']['get']['operationId'] = 'listReceipts';
        $document['x-docuccino']['workflows'][0]['steps'][] = [
            'id' => 'receipt',
            'operation' => 'op:v1:aaaaaareceiptlst',
        ];

        return $document;
    });

    $steps = $description['workflows'][0]['steps'];

    expect(end($steps)['stepId'])->toBe('receipt')
        ->and(end($steps))->not->toHaveKey('successCriteria');
});

it('leaves out a step whose operation the document publishes under no operationId', function (): void {
    // An Arazzo runner told to call an operation its source description has not got fails at run time
    // with nothing to say why, so the step is dropped and the build says so instead.
    [$description, $codes] = emitArazzo(function (array $document): array {
        $document['x-docuccino']['workflows'][0]['steps'][] = [
            'id' => 'receipt',
            'operation' => 'op:v1:aaaaaareceiptlst',
        ];

        return $document;
    });

    expect(array_column($description['workflows'][0]['steps'], 'stepId'))->toBe(['reserve', 'pay'])
        ->and($codes)->toBe(['arazzo.step-unresolved']);
});

it('leaves out a step whose operation the document does not publish at all', function (): void {
    [$description, $codes] = emitArazzo(function (array $document): array {
        $document['x-docuccino']['workflows'][0]['steps'][0]['operation'] = 'op:v1:zzzzzzzzzzzzzzzz';

        return $document;
    });

    expect(array_column($description['workflows'][0]['steps'], 'stepId'))->toBe(['pay'])
        ->and($codes)->toBe(['arazzo.step-unresolved']);
});

/*
 * Arazzo requires at least one workflow and at least one source description, so there is no empty form
 * of the document to write. Writing nothing and saying so beats writing a file that fails the
 * specification it names.
 */
it('writes nothing for a document that declares no workflows', function (): void {
    [$description, $codes] = emitArazzo(function (array $document): array {
        unset($document['x-docuccino']['workflows']);

        return $document;
    });

    expect($description)->toBe([])->and($codes)->toBe(['arazzo.no-workflows']);
});

it('writes nothing where every workflow lost all of its steps', function (): void {
    [$description, $codes] = emitArazzo(function (array $document): array {
        foreach ($document['x-docuccino']['workflows'][0]['steps'] as $index => $step) {
            $document['x-docuccino']['workflows'][0]['steps'][$index]['operation'] = 'op:v1:zzzzzzzzzzzzzzzz';
        }

        return $document;
    });

    expect($description)->toBe([])
        ->and($codes)->toBe(['arazzo.step-unresolved', 'arazzo.step-unresolved', 'arazzo.no-workflows']);
});

it('names the source description the steps live in', function (): void {
    [$description] = emitArazzo();

    expect($description['sourceDescriptions'])->toBe([[
        'name' => 'openapi',
        'url' => 'openapi.json',
        'type' => 'openapi',
    ]]);
});

it('points at the OpenAPI artifact the caller says will be there', function (): void {
    [$description] = emitArazzo(options: (new EmitOptions)->withSourceUrl('api.yaml'));

    expect($description['sourceDescriptions'][0]['url'])->toBe('api.yaml');
});

it('takes its own title and version from the document it describes', function (): void {
    [$description] = emitArazzo();

    expect($description['arazzo'])->toBe('1.1.0')
        ->and($description['info'])->toBe(['title' => 'Baskets API', 'version' => '2026-09-01']);
});

it('emits YAML on request', function (): void {
    $result = (new ArazzoEmitter)->emitWithReport(workflowDocument(), (new EmitOptions)->withYaml());

    expect($result->output)->toStartWith('arazzo: 1.1.0')
        ->and($result->output)->toContain('workflowId: checkout');
});

/*
 * The oracle EXECUTED rather than asserted. A schema registered but not constraining — a permissive
 * stub shadowing the real one, a resolver that quietly answered nothing — passes every document above
 * exactly as it passes a valid one, which is the failure the Postman schema shipped with until somebody
 * looked. Each row below is a document Arazzo's own schema has to refuse.
 */
it('refuses a description that does not answer to the Arazzo schema', function (string $case, callable $break): void {
    [$description] = emitArazzo();

    /** @var array<string, mixed> $broken */
    $broken = $break($description);

    $result = ArazzoSchema::validator()->validate(Helper::toJSON($broken), ArazzoSchema::PUBLISHED);

    expect($result->isValid())->toBeFalse($case);
})->with([
    'no workflows at all' => ['no workflows at all', function (array $d): array {
        $d['workflows'] = [];

        return $d;
    }],
    'no source descriptions' => ['no source descriptions', function (array $d): array {
        $d['sourceDescriptions'] = [];

        return $d;
    }],
    'a version the spec does not name' => ['a version the spec does not name', function (array $d): array {
        $d['arazzo'] = '2.0.0';

        return $d;
    }],
    'a step that calls nothing' => ['a step that calls nothing', function (array $d): array {
        unset($d['workflows'][0]['steps'][0]['operationId']);

        return $d;
    }],
    'a parameter in a place Arazzo has not got' => ['a parameter in a place Arazzo has not got', function (array $d): array {
        $d['workflows'][0]['steps'][0]['parameters'][0]['in'] = 'body';

        return $d;
    }],
    'a workflow with no id' => ['a workflow with no id', function (array $d): array {
        unset($d['workflows'][0]['workflowId']);

        return $d;
    }],
]);
