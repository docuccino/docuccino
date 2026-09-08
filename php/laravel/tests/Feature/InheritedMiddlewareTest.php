<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * A route that INHERITS its middleware from a group, described the way a documentation build describes
 * it. Writing middleware on the route is the shape every other suite here exercises; inheriting it from
 * a group is the idiomatic Laravel shape, and it is the shape no committed document stood in — because
 * a group only reaches the router when the HTTP kernel is constructed, and testbench constructs one
 * before the first test while a console build never does ({@see refreshWithoutHttpKernel()}).
 *
 * So each row below runs on an unsynced router, and the group arrives the way an application's own
 * arrives: on the `Middleware` configuration object the framework applies when that kernel resolves
 * ({@see registerAppMiddlewareGroup()}). The two facts a group carries here are the two the issue named
 * — an authenticator, which decides the 401 and the security requirement, and a `throttle`, which
 * decides the 429 and its rate-limit headers.
 */
beforeEach(function (): void {
    refreshWithoutHttpKernel();
    bindStubEngine();

    registerAppMiddlewareGroup('docuccino-inherited', ['auth:web', 'throttle:60,1']);

    /** @var Router $router */
    $router = app('router');
    // The same two middleware three ways: inherited from the group, written on the route, and absent.
    // The first two must publish one operation; the third is the anti-vacuity row, so a document that
    // published these facts everywhere could not pass either.
    $router->get('api/inherited/from-group', [FormController::class, 'index'])->middleware('docuccino-inherited');
    $router->get('api/inherited/on-the-route', [FormController::class, 'index'])->middleware(['auth:web', 'throttle:60,1']);
    $router->get('api/inherited/neither', [FormController::class, 'index']);
    $router->getRoutes()->refreshNameLookups();

    config()->set('docuccino.documents', [
        'inherited' => [
            'info' => ['title' => 'Inherited Middleware', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/inherited/*']],
            'error_responses' => 'default',
            'security' => [
                'schemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
                'auto_detect_middleware' => 'auth*',
                'default' => [['bearer' => []]],
            ],
        ],
    ]);
});

/** One build of the `inherited` document — the whole result, because one row reads its diagnostics. */
function inheritedBuild(): GenerationResult
{
    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.inherited');
    $config = app(DocumentConfigFactory::class)->make('inherited', $raw, 'skeleton');

    return app(DocumentGenerator::class)->generate($config, app(TypeEngine::class));
}

/** @return array<string, mixed> */
function inheritedDocument(): array
{
    return inheritedBuild()->document->toArray();
}

/**
 * The whole operation rather than the facts a regression would take away: asserting the 401 and the 429
 * separately passes on a reader that answers the two shapes differently in some third respect. Only the
 * path-derived identities differ, so they are the only thing dropped before the comparison.
 */
it('publishes the same operation for middleware inherited from a group as for middleware on the route', function (): void {
    $paths = inheritedDocument()['paths'];

    $strip = function (mixed $node) use (&$strip): mixed {
        if (! is_array($node)) {
            return $node;
        }

        unset($node['operationId']);
        if (is_array($node['x-docuccino'] ?? null)) {
            unset($node['x-docuccino']['id']);
        }

        return array_map($strip, $node);
    };

    expect($strip($paths['/api/inherited/from-group']['get']))
        ->toBe($strip($paths['/api/inherited/on-the-route']['get']));
});

/**
 * And the four facts named, so a failure says which half of the contract went — and the route carrying
 * none of them beside them, so the row cannot pass on a document that publishes them unconditionally.
 */
it('carries the 401, the security requirement, the 429 and its rate-limit headers for the inherited group', function (): void {
    $document = inheritedDocument();
    $paths = $document['paths'];
    $inherited = $paths['/api/inherited/from-group']['get'];

    // The 429 is shared by both throttled routes, so it is published once as a component and referenced
    // — the headers a client reads are on the component, which is where a generator will look for them.
    expect(array_map(strval(...), array_keys($inherited['responses'])))->toContain('401')->toContain('429')
        ->and($inherited['security'])->toBe([['bearer' => []]])
        ->and($inherited['responses']['429']['$ref'])->toBe('#/components/responses/TooManyRequests')
        ->and(array_keys($document['components']['responses']['TooManyRequests']['headers']))->toBe([
            'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset',
        ]);

    $neither = $paths['/api/inherited/neither']['get'];
    expect($neither['responses'])->not->toHaveKey('401')
        ->and($neither['responses'])->not->toHaveKey('429')
        ->and($neither)->not->toHaveKey('security');
});

it('emits the inherited-middleware document byte-identical to its golden', function (): void {
    assertGolden('workbench-inherited-middleware.uir.json', (new UirEmitter)->emit(UirDocument::fromArray(inheritedDocument())));
});

/**
 * The same bytes for the same application read through a router something else has already synced —
 * which is the state an HTTP request finds, and the state every other suite here runs in. The two
 * readings of one application must agree, and a document that only comes out right when a kernel
 * happens to have been constructed first is the defect this file exists to catch.
 */
it('emits the same document under a router the HTTP kernel has already synced', function (): void {
    app()->make(HttpKernelContract::class);

    // The premise, from the framework: this really is the other reading, and it is not the same router.
    expect(app('router')->getMiddlewareGroups())->toHaveKey('docuccino-inherited');

    assertGolden('workbench-inherited-middleware.uir.json', (new UirEmitter)->emit(UirDocument::fromArray(inheritedDocument())));
});
