<?php

declare(strict_types=1);

use Docuccino\Attributes\Response;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Extensions\AttributeResponsesExtension;

/**
 * Where the redirect layers meet: inference lands a redirect on the `3XX` range because the return
 * site names no code, and `#[Response(status: 302)]` names one. The attribute layer holds both facts,
 * so it is where the superseded range is retracted.
 */
function responsesAfterAttributes(array $attributes, string $inferredRange = '3XX'): array
{
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/things'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet($attributes),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
    );

    // Exactly what InferredResponsesExtension leaves behind for a bare RedirectResponse return.
    $operation = new OperationDraft;
    $range = $operation->response($inferredRange);
    $range->setDescription('Redirect', Contribution::fallback());
    $range->set('headers', [
        'Location' => [
            'description' => 'The URL to follow.',
            'schema' => ['type' => 'string', 'format' => 'uri-reference'],
        ],
    ], Contribution::inference());

    (new AttributeResponsesExtension)->handle($operation, $context);

    return $operation->responseStatuses();
}

it('retires the inferred redirect range once an attribute names the code', function (): void {
    // The defect this closes: the document published 302 AND 3XX, so a consumer read "always 302" and
    // "any 3xx may happen" side by side, and every generated client saw two success responses.
    expect(responsesAfterAttributes([new Response(status: 302, description: 'Follow the Location header.')]))
        ->toBe(['302']);
});

it('retires it for any code in the range, not just the redirect default', function (int $status): void {
    expect(responsesAfterAttributes([new Response(status: $status)]))->toBe([(string) $status]);
})->with([
    'moved permanently' => [301],
    'found' => [302],
    'see other' => [303],
    'temporary redirect' => [307],
    'permanent redirect' => [308],
]);

it('keeps the range beside a declared status of another class', function (int $status): void {
    // Declaring the success body, or an error, says nothing about which redirect the endpoint answers
    // with — so the honest range stays.
    // Byte-sorted, so which of the two comes first is a fact about the digits, not about this rule.
    expect(responsesAfterAttributes([new Response(status: $status)]))
        ->toHaveCount(2)
        ->toContain('3XX', (string) $status);
})->with(['success' => [200], 'not found' => [404], 'unavailable' => [503]]);

it('keeps every declared code when an endpoint really answers with several', function (): void {
    // The range's job was to stand in for one unknown code. An endpoint that answers 301 sometimes and
    // 302 others declares both, and the document says exactly that rather than "any 3xx".
    expect(responsesAfterAttributes([new Response(status: 301), new Response(status: 302)]))
        ->toBe(['301', '302']);
});

it('leaves a redirect nothing declared exactly as inference documented it', function (): void {
    expect(responsesAfterAttributes([]))->toBe(['3XX']);
});
