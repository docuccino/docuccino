<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/**
 * Feature coverage for docuccino:explain — the three outcomes and their exit codes, the spellings a
 * reader can name an operation with, and the report the answer arrives as.
 *
 * The command builds its own document, so the trail it reads is always the full one: `--provenance`
 * levels only decide how much survives into an exported artifact, and these tests pin that an
 * `overrode` entry — which only ever exists at `full` — reaches the report.
 */
beforeEach(function (): void {
    bindStubEngine();
});

it('explains the operation a method and URI name', function (): void {
    $exit = Artisan::call('docuccino:explain', ['route' => 'POST /api/tickets', 'document' => 'default']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('POST /api/tickets')
        ->and($output)->toContain('ValidationController@store')
        ->and($output)->toContain('document "default"')
        // The ladder, taught while the question is answered.
        ->and($output)->toContain('fallback › inference › integration › docblock › attribute › overlay › config')
        // The winner, its rung and where to open.
        ->and($output)->toContain('✓ attribute   "The created widget."')
        ->and($output)->toContain('workbench/app/Http/Controllers/ValidationController.php:21');
});

it('shows what a higher rung shadowed, which only the full trail records', function (): void {
    Artisan::call('docuccino:explain', ['route' => 'POST /api/tickets', 'document' => 'default']);
    $output = Artisan::output();

    expect($output)->toContain('✗ fallback    "OK"')
        ->and($output)->toContain('1 shadowed');
});

it('reads a body the operation only points at', function (): void {
    Artisan::call('docuccino:explain', ['route' => 'GET /api/forms/{form}', 'document' => 'default']);
    $output = Artisan::output();

    expect($output)->toContain('responses.404  → #/components/responses/NotFound')
        ->and($output)->toContain('parameters.path:form');
});

it('answers to every spelling of one operation', function (string $route): void {
    $exit = Artisan::call('docuccino:explain', ['route' => $route, 'document' => 'default']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('POST /api/tickets');
})->with([
    'method and URI' => ['POST /api/tickets'],
    'method and URI with no leading slash' => ['post api/tickets'],
    'a URI only one verb answers' => ['/api/tickets'],
    'a URI with the base path left off' => ['tickets'],
]);

it('narrows a URI several verbs answer', function (): void {
    $exit = Artisan::call('docuccino:explain', ['route' => 'api/model-widgets/{id}', '--method' => 'delete', 'document' => 'default']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain("DELETE /api/model-widgets/{id}\n─");
});

it('lists every operation an ambiguous query names, and picks none of them', function (): void {
    $exit = Artisan::call('docuccino:explain', ['route' => 'api/model-widgets/{id}', 'document' => 'default']);
    $output = Artisan::output();

    expect($exit)->toBe(2)
        ->and($output)->toContain('2 operations match "api/model-widgets/{id}"')
        ->and($output)->toContain('DELETE')
        ->and($output)->toContain('GET')
        ->and($output)->toContain('php artisan docuccino:explain "DELETE /api/model-widgets/{id}"');
});

it('turns a fragment into a menu rather than a dead end', function (): void {
    $exit = Artisan::call('docuccino:explain', ['route' => 'article', 'document' => 'default']);
    $output = Artisan::output();

    expect($exit)->toBe(2)
        ->and($output)->toContain('7 operations match "article"')
        ->and($output)->toContain('/api/paginated-articles');
});

it('says what could be typed instead when nothing matches', function (): void {
    $exit = Artisan::call('docuccino:explain', ['route' => 'api/nowhere', 'document' => 'default']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('No operation matches "api/nowhere".')
        ->and($output)->toContain('operations are published. Name one by:')
        ->and($output)->toContain('method + URI')
        ->and($output)->toContain('routes.include / routes.exclude');
});

it('says so, and what it does not mean, when an operation recorded nothing', function (): void {
    $exit = Artisan::call('docuccino:explain', ['route' => 'GET /api/ping', 'document' => 'default']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('No provenance recorded for this operation.')
        ->and($output)->toContain('`--provenance` only decides how much of it');
});

it('names the document an answer is about when several are configured', function (): void {
    config()->set('docuccino.documents.public', config('docuccino.documents.default'));
    config()->set('docuccino.documents.public.routes.include', ['api/tickets']);

    $exit = Artisan::call('docuccino:explain', ['route' => 'POST /api/tickets']);
    $output = Artisan::output();

    expect($exit)->toBe(2)
        ->and($output)->toContain('2 operations match')
        ->and($output)->toContain('public')
        ->and($output)->toContain('default');
});

it('explains one document when the argument names one', function (): void {
    config()->set('docuccino.documents.public', config('docuccino.documents.default'));
    config()->set('docuccino.documents.public.routes.include', ['api/tickets']);

    $exit = Artisan::call('docuccino:explain', ['route' => 'POST /api/tickets', 'document' => 'public']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('document "public"');
});

it('publishes the same trail as JSON for a tool to read', function (): void {
    $exit = Artisan::call('docuccino:explain', ['route' => 'POST /api/tickets', 'document' => 'default', '--json' => true]);

    /** @var array<string, mixed> $payload */
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($payload['status'])->toBe('explained')
        ->and($payload['operation']['path'])->toBe('/api/tickets')
        ->and($payload['operation']['method'])->toBe('post')
        ->and($payload['nodes'][0]['label'])->toBe('operation')
        ->and($payload['nodes'][0]['pointer'])->toBe('/paths/~1api~1tickets/post');
});

it('reports the outcome in JSON whichever outcome it is', function (string $route, string $status, int $exit, int $matches): void {
    expect(Artisan::call('docuccino:explain', ['route' => $route, 'document' => 'default', '--json' => true]))->toBe($exit);

    /** @var array<string, mixed> $payload */
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe($status)
        ->and($payload['query'])->toBe($route)
        ->and($payload['matches'])->toHaveCount($matches);
})->with([
    'nothing matched' => ['api/nowhere', 'no-match', 1, 0],
    'several matched' => ['api/model-widgets/{id}', 'ambiguous', 2, 2],
]);

it('rejects a method it does not know rather than ignoring it', function (): void {
    $exit = Artisan::call('docuccino:explain', ['route' => 'api/tickets', '--method' => 'fetch', 'document' => 'default']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Unknown --method "fetch"');
});

/**
 * The report is read on someone else's machine as often as on the one that built it — pasted into an
 * issue, or scrolled back in a CI log — and a provenance source is already relativised where it is
 * recorded. This fixes that the report prints it as recorded rather than growing a second notion of a
 * publishable path.
 */
it('names no machine path in a report', function (): void {
    Artisan::call('docuccino:explain', ['route' => 'POST /api/tickets', 'document' => 'default']);
    $output = Artisan::output();

    expect($output)->not->toContain(base_path())
        ->and($output)->toContain('workbench/app/Http/Controllers/ValidationController.php:21');
});

it('fails for an unknown document', function (): void {
    expect(Artisan::call('docuccino:explain', ['route' => 'api/tickets', 'document' => 'nope']))->toBe(1);
});

it('stops on a disabled install', function (): void {
    config()->set('docuccino.enabled', false);

    $exit = Artisan::call('docuccino:explain', ['route' => 'api/tickets']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Docuccino is disabled');
});
