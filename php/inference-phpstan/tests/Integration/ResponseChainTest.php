<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A response is sent as the last call to change it left it, so each link of a fluent chain is read for what
 * it changes and nothing more: `setContent()` and `setData()` replace the body and leave the status, a cookie
 * changes nothing documented, and a link that cannot be read is unknown only in what it could have touched.
 * A link that might have touched anything — one not named, or one an application's subclass overrides —
 * leaves nothing the response was built with standing but its class. An application's subclass is chained
 * as the framework's response it derives from, and a local holding a `new JsonResponse` is read as what it
 * was built with.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> */
function responseChainAnalyses(): array
{
    static $analyses = null;

    return $analyses ??= FixtureRunner::analyzeMany(
        'app/Http/Controllers/ResponseChainController.php',
        'App\\Http\\Controllers\\ResponseChainController',
        [
            'content', 'data', 'dataFromRequest', 'dataThenStatus', 'headers', 'header', 'cookie', 'withCookie',
            'withoutCookie', 'pretty', 'forceObject', 'callback', 'noCallback', 'unknownLink', 'twoUnread', 'twoUnreadThenStatus',
            'subclass', 'subclassHeader', 'subclassStream', 'subclassRedirect', 'overridden', 'overriddenLocal',
            'localFactory', 'localJson', 'localPlain', 'localData', 'localContent',
        ],
    );
}

function responseChainReturn(string $method): DType
{
    $returns = ActionAnalysis::fromArray(responseChainAnalyses()[$method])->returns;
    expect($returns)->toHaveCount(1);

    return $returns[0]->type;
}

const RESPONSE_CHAIN_JSON = 'Illuminate\\Http\\JsonResponse';

/** @param  array<string, DType>  $fields */
function responseChainBody(array $fields): ArrayShapeT
{
    return new ArrayShapeT(array_map(static fn (string $key, DType $type): ArrayShapeField => new ArrayShapeField($key, $type), array_keys($fields), $fields));
}

it('documents the body a link replaces, beside the status the response was built with', function (string $method, DType $body, ?string $mediaType): void {
    // `response('queued', 202)->setContent('accepted')` sends `accepted` with a 202: replacing the content
    // touches neither the status nor, for text, the media type. `setData()` likewise.
    expect(responseChainReturn($method))->toEqual(new ClassT(RESPONSE_CHAIN_JSON, [$body, new LiteralT(202), ...($mediaType === null ? [] : [new LiteralT($mediaType)])]));
})->with([
    'setContent(\'accepted\')' => ['content', new LiteralT('accepted'), 'text/html'],
    'setData([\'accepted\' => true])' => ['data', responseChainBody(['accepted' => new LiteralT(true)]), null],
    // The status set after the data is the one sent, over the 200 it was built with.
    'setData(…)->setStatusCode(202)' => ['dataThenStatus', responseChainBody(['accepted' => new LiteralT(true)]), null],
    // A local's data set on a line of its own replaces it as a chain would.
    'new JsonResponse(…, 202), then setData(…) on its own line' => ['localData', responseChainBody(['accepted' => new LiteralT(true)]), null],
    '$response->setContent(\'accepted\')->cookie(…) on its own line' => ['localContent', new LiteralT('accepted'), 'text/html'],
])->group('fixture');

it('leaves the response as it was built where a link touches nothing documented', function (string $method): void {
    // A cookie, a header that is not the media type, a pretty-printed encoding and a null callback leave the
    // body, the status and the media type the server sends as they were.
    expect(responseChainReturn($method))->toEqual(new ClassT(RESPONSE_CHAIN_JSON, [responseChainBody(['queued' => new LiteralT(true)]), new LiteralT(202)]));
})->with(['header', 'cookie', 'withCookie', 'withoutCookie', 'pretty', 'noCallback', 'localFactory'])->group('fixture');

it('claims no body but keeps the status where a link may have changed the body in a way nobody can read', function (string $method): void {
    // Data read off the request, an encoding that sends an empty list as `{}`, a JSONP callback that wraps
    // the body in JavaScript, and content and headers nobody can read: the body is unknown, the media type no
    // longer the one it was built with, and the 202 still the status sent.
    expect(responseChainReturn($method))->toEqual(new ClassT(RESPONSE_CHAIN_JSON, [new UnknownT('payload not folded'), new LiteralT(202)]));
})->with(['forceObject', 'callback', 'twoUnread', 'twoUnreadThenStatus'])->group('fixture');

it('reads data handed to setData() as the constructor reads it', function (): void {
    // `$request->all()` is whatever array the request carries: a body, but not the one it was built with.
    $body = responseChainReturn('dataFromRequest')->typeArgs[0] ?? null;

    expect($body)->not->toBeInstanceOf(UnknownT::class)
        ->and($body)->not->toEqual(responseChainBody(['queued' => new LiteralT(true)]));
})->group('fixture');

it('keeps only the class where a link may have changed anything', function (string $method): void {
    // `setNotModified()` sends a 304 with no body; nobody named it, so nothing the response was built with
    // is claimed. An application's own `setStatusCode()` may do anything, and this one keeps its 423.
    expect(responseChainReturn($method))->toEqual(new ClassT(RESPONSE_CHAIN_JSON, [new UnknownT('payload not folded'), new UnknownT('status not folded')]));
})->with(['unknownLink', 'overridden', 'overriddenLocal'])->group('fixture');

it('reads a chain on an application\'s subclass over what its constructor hands the framework\'s', function (string $method, DType $expected): void {
    // `(new GoneJsonResponse)->setStatusCode(404)` sends its fixed body with a 404, not the bare class's 200
    // nor the 410 it was built with; a stream or redirect subclass keeps its own class.
    expect(responseChainReturn($method))->toEqual($expected);
})->with([
    'setStatusCode(404)' => ['subclass', new ClassT(RESPONSE_CHAIN_JSON, [responseChainBody(['message' => new LiteralT('This resource is gone.')]), new LiteralT(404)])],
    'header(\'Content-Type\', …)' => ['subclassHeader', new ClassT(RESPONSE_CHAIN_JSON, [responseChainBody(['message' => new LiteralT('This resource is gone.')]), new LiteralT(410), new LiteralT('application/problem+json')])],
    'a stream\'s setStatusCode(503)' => ['subclassStream', new ClassT('App\\Http\\Responses\\LockedStreamResponse', [new UnknownT('payload not folded'), new LiteralT(503)])],
    'a redirect\'s setStatusCode(308)' => ['subclassRedirect', new ClassT('App\\Http\\Responses\\MovedResponse', [new UnknownT('payload not folded'), new LiteralT(308)])],
])->group('fixture');

it('reads a local holding a response built with new as what it was built with', function (string $method, DType $body, ?string $mediaType): void {
    // `$response = new JsonResponse(['queued' => true], 202); return $response;` sends that body with a 202,
    // exactly as returning the `new` itself does, and not the erased generic the local is typed as.
    expect(responseChainReturn($method))->toEqual(new ClassT(RESPONSE_CHAIN_JSON, [$body, new LiteralT(202), ...($mediaType === null ? [] : [new LiteralT($mediaType)])]));
})->with([
    'new JsonResponse' => ['localJson', responseChainBody(['queued' => new LiteralT(true)]), null],
    'new Response' => ['localPlain', new LiteralT('queued'), 'text/html'],
])->group('fixture');

it('documents the media type a header link sets over a body it leaves', function (): void {
    expect(responseChainReturn('headers'))->toEqual(new ClassT(RESPONSE_CHAIN_JSON, [responseChainBody(['queued' => new LiteralT(true)]), new LiteralT(202), new LiteralT('application/vnd.queue+json')]));
})->group('fixture');
