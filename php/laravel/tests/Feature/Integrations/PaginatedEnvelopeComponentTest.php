<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\Pagination\PagesController;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\PaginationEngine;
use Illuminate\Routing\Router;

/**
 * The page-of-X hoist through the whole adapter: every paginator kind, one item type paginated twice,
 * a second item type beside it, and the two shapes that keep an envelope inline. The kind comes from a
 * scripted trace and the item type from a scripted return, so what these rows exercise is the real
 * response path — {@see PageComponentTest} covers the naming table on its own.
 *
 * Route URIs all sort after everything the workbench states, so nothing here perturbs it.
 */
beforeEach(function (): void {
    /** @var Router $router */
    $router = app('router');
    foreach (array_keys(PaginationEngine::TERMINALS) as $action) {
        $router->get('api/zz-pages-'.$action, [PagesController::class, $action]);
    }

    app()->instance(TypeEngine::class, PaginationEngine::make());
});

it('publishes one page component per item type and paginator kind', function (string $action, ?string $component): void {
    $document = generateDocument()->document->toArray();
    $schema = $document['paths']['/api/zz-pages-'.$action]['get']['responses']['200']['content']['application/json']['schema'];

    if ($component === null) {
        // Vague but true: the whole envelope, restated on the operation, exactly as it shipped before.
        expect(stripDocuccino($schema))->toHaveKeys(['type', 'properties', 'required'])
            ->and($schema)->not->toHaveKey('$ref')
            ->and($schema['required'])->toBe(['data', 'links', 'meta']);

        return;
    }

    expect(stripDocuccino($schema))->toBe(['$ref' => '#/components/schemas/'.$component])
        ->and($document['components']['schemas'])->toHaveKey($component);

    // The envelope references the item's own component rather than inlining a second copy of it.
    $envelope = $document['components']['schemas'][$component];
    expect($envelope['required'])->toBe(['data', 'links', 'meta'])
        ->and($envelope['properties']['data']['items'])->toHaveKey('$ref');
})->with([
    'length-aware' => ['articles', 'ArticleResourcePage'],
    'simple' => ['simpleArticles', 'ArticleResourceSimplePage'],
    'cursor' => ['cursorArticles', 'ArticleResourceCursorPage'],
    'a second item type' => ['authors', 'AuthorResourcePage'],
    // No class to name a page of, and a named class whose schema never became a component: both keep
    // the envelope where it was.
    'an item type that is no class' => ['shapedItems', null],
    'an item class the analyser cannot expand' => ['unexpandable', null],
]);

it('lands two operations paginating one item type on the same component', function (): void {
    $document = generateDocument()->document->toArray();

    $first = $document['paths']['/api/zz-pages-articles']['get']['responses']['200']['content']['application/json']['schema'];
    $second = $document['paths']['/api/zz-pages-moreArticles']['get']['responses']['200']['content']['application/json']['schema'];

    expect($first['$ref'])->toBe('#/components/schemas/ArticleResourcePage')
        ->and($second['$ref'])->toBe($first['$ref']);

    // One page type per item type is the point of the hoist — a second one under a suffixed name would
    // hand an SDK generator back the duplicate it exists to prevent.
    $suffixed = array_filter(
        array_keys($document['components']['schemas']),
        static fn (string $name): bool => str_starts_with($name, 'ArticleResourcePage_'),
    );
    expect($suffixed)->toBe([]);
});

it('never takes the name of the item type it is a page of', function (): void {
    $schemas = generateDocument()->document->toArray()['components']['schemas'];

    // The page is a facet of the item's identity, so the two never contested a name and the item keeps
    // the plain one a generated client is already written against.
    expect($schemas)->toHaveKeys(['ArticleResource', 'ArticleResourcePage', 'AuthorResource', 'AuthorResourcePage'])
        // …and the shape under that name is still the item's own, not a page that displaced it.
        ->and(array_keys($schemas['ArticleResource']['properties']))->not->toContain('links')
        ->and(array_keys($schemas['ArticleResourcePage']['properties']))->toBe(['data', 'links', 'meta']);
});

it('serves the page components from a warm cache byte-identically', function (): void {
    fragmentCacheDir('pages');
    $engine = new CountingTypeEngine(PaginationEngine::make());
    app()->instance(TypeEngine::class, $engine);

    // A page component is registered by the route that references it and travels on that route's
    // fragment; two routes sharing one page each carry it, so a warm hit has to put back exactly one.
    $cold = (new UirEmitter)->emit(generateDocument()->document);
    expect($engine->analyzeCount)->toBeGreaterThan(0);

    $engine->analyzeCount = 0;
    $warm = (new UirEmitter)->emit(generateDocument()->document);

    expect($warm)->toBe($cold)
        ->and($engine->analyzeCount)->toBe(0);
});

it('restores the inline envelope byte-for-byte when hoisting is off', function (): void {
    $hoisted = generateDocument()->document->toArray();
    $inline = generateDocument(function (array $raw): array {
        $raw['representation']['pagination']['components'] = false;

        return $raw;
    })->document->toArray();

    $body = static fn (array $document, string $action): array => $document['paths']['/api/zz-pages-'.$action]['get']['responses']['200']['content']['application/json']['schema'];

    // Off, the envelope is on the operation and the page component is gone entirely.
    expect($inline['components']['schemas'])->not->toHaveKey('ArticleResourcePage')
        ->and(stripDocuccino($body($inline, 'articles'))['properties'])->toHaveKeys(['data', 'links', 'meta']);

    // And the two shapes really are the same envelope, just placed differently.
    expect(stripDocuccino($body($inline, 'articles')))
        ->toBe(stripDocuccino($hoisted['components']['schemas']['ArticleResourcePage']));
});
