<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Pipeline\FragmentCache;
use Docuccino\Core\Pipeline\OperationFragment;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Fragment-cache soundness for the interprocedural trace, and the bound arithmetic that has to stay
 * unmoved while it is fixed. A trace's file set is what keys the fragment it feeds, so every file a
 * harvested fact was WRITTEN in belongs on it — including a trait's, which PHP hands to the walk of the
 * using class's file and so never names. What the trace recovers is {@see QueryBuilderTraceTest}.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The export chain's allow-list, written in the trait the query class imports. */
const EXPORT_FILTERS = ["'sku'", "AllowedFilter::exact('status')"];

/** Its sortable columns, one hop past the trait. */
const EXPORT_SORTS = ["'sku'", "'created_at'"];

/**
 * @return array<string, mixed>
 */
function exportTrace(int $fileBudget = 40, int $traceDepth = 4): array
{
    return FixtureRunner::traceQbBounds(
        $fileBudget,
        $traceDepth,
        'app/Http/Controllers/ExportListController.php',
        'App\\Http\\Controllers\\ExportListController',
        'listExports',
    );
}

/**
 * @param  array<string, mixed>  $trace
 * @return list<string>
 */
function exportDependencyNames(array $trace): array
{
    /** @var list<string> $files */
    $files = $trace['dependencyFiles'];

    return array_map(static fn (string $file): string => basename($file), $files);
}

/**
 * The modular facet query, traced with the real visitor: its allow-list is SPREAD from a helper, and an
 * array return is not a type the trace follows — so the descent gate declines that hop and the return
 * fold is the only reader of the concern's body.
 *
 * @return array<string, mixed>
 */
function exportFacetTrace(): array
{
    return FixtureRunner::traceQbEnrich(
        'modules/Billing/ExportFacetQuery.php',
        'Modules\\Billing\\ExportFacetQuery',
        'query',
    );
}

it('depends on the file a traced body was written in', function (): void {
    // The allow-list is written in the concern the query class imports, and PHP reports the method as the
    // query class's own — so the walk harvests these entries out of a file it never opened by name. Only
    // the using class's file was ever recorded, and editing the trait then left every warm route
    // publishing filters the code no longer offers.
    $trace = exportTrace();

    expect($trace['filters'])->toBe(EXPORT_FILTERS) // the fact, so the row cannot pass on an empty harvest
        ->and(exportDependencyNames($trace))
        ->toContain('FiltersExports.php')
        ->toContain('ExportIndexQuery.php')
        // The trace's ROOT is the same fact one level up: the route resolved to the controller's file and
        // the action it runs is written in the concern that controller imports.
        ->toContain('ListsExports.php')
        ->toContain('ExportListController.php');
})->group('fixture');

it('recovers the same facts at each descent bound, and depends on the trait only where it read one', function (
    int $fileBudget,
    int $traceDepth,
    array $filters,
    array $sorts,
    array $default,
    array $terminals,
    bool $dependsOnTrait,
): void {
    // The bound frontier, measured rather than argued. Each fact below sits one hop further out than the
    // last, so a budget or a depth one short of the chain has to lose exactly one of them — which is the
    // guard that recording the trait's file costs the traversal nothing: were it charged a slot, the row
    // at a budget of 4 would stop reaching the sorts one hop past it.
    //
    // The last row is the shipped default (40 / 4). The `dependsOnTrait` column is the other half of the
    // same claim: a trait file is a dependency where a body was read out of it and not merely where a
    // callee resolved into it, which is why the depth-1 row wants it absent.
    $trace = exportTrace($fileBudget, $traceDepth);

    expect($trace['filters'])->toBe($filters)
        ->and($trace['sorts'])->toBe($sorts)
        ->and($trace['default'])->toBe($default)
        ->and($trace['terminals'])->toBe($terminals)
        ->and(in_array('FiltersExports.php', exportDependencyNames($trace), true))->toBe($dependsOnTrait);
})->with([
    'a budget for the action alone' => [1, 4, [], [], [], ['paginateList'], false],
    'one more, spent on the custom terminal' => [2, 4, [], [], [], ['paginateList', 'paginate'], false],
    'one more, spent on the query class the trait is imported into' => [3, 4, EXPORT_FILTERS, [], ["'sku'"], ['paginateList', 'paginate'], true],
    'one more, spent on the helper a hop past the trait' => [4, 4, EXPORT_FILTERS, EXPORT_SORTS, ["'sku'"], ['paginateList', 'paginate'], true],
    'a depth reaching the query class but not the trait body' => [40, 1, [], [], ["'sku'"], ['paginateList', 'paginate'], false],
    'a depth reaching the trait body but not past it' => [40, 2, EXPORT_FILTERS, [], ["'sku'"], ['paginateList', 'paginate'], true],
    'a depth reaching the helper past the trait' => [40, 3, EXPORT_FILTERS, EXPORT_SORTS, ["'sku'"], ['paginateList', 'paginate'], true],
    'the shipped bounds' => [40, 4, EXPORT_FILTERS, EXPORT_SORTS, ["'sku'"], ['paginateList', 'paginate'], true],
])->group('fixture');

it('depends on the file a folded return was written in', function (): void {
    // The other half of the same rule, on the one hop a fold may make that a descent may not: the helper's
    // array return is no followed type, so this concern's body is read by the fold alone. Both facets
    // recover and the file they are written in is on the list — and nothing else is, so the concern got
    // there by being read rather than by being walked.
    $trace = exportFacetTrace();

    /** @var list<array<string, mixed>> $filters */
    $filters = $trace['filters'];

    expect(array_column($filters, 'name'))->toBe(['facet', 'label'])
        ->and($trace['visitedBasenames'])->toBe(['NamesExportFacets.php', 'ExportFacetQuery.php']);
})->group('fixture');

it('invalidates a cached fragment when a file a traced fact was written in is edited', function (string $subject, string $edited): void {
    // The end of the chain, through the real cache: what the trace reports is what a fragment stores, and
    // editing either concern has to make the entry stale. Without its file on the list the entry stays
    // warm, which is a route publishing an allow-list its code no longer enforces.
    /** @var list<string> $dependencies */
    $dependencies = ($subject === 'descended' ? exportTrace() : exportFacetTrace())['dependencyFiles'];
    $path = FixtureRunner::path($edited);
    $before = file_get_contents($path);
    expect($before)->toBeString();

    $dir = sys_get_temp_dir().'/docuccino-trace-deps-'.uniqid('', true);
    $cache = static fn (): FragmentCache => new FragmentCache(true, $dir, 't', 's', 'i');
    $key = 'trace-written-in';

    try {
        $cache()->put($key, new OperationFragment('/exports', 'get', (new OperationDraft)->freeze(), 'GET /exports'), $dependencies);

        // Warm to begin with — otherwise the row would pass with the whole dependency list dropped.
        expect($cache()->get($key))->not->toBeNull();

        file_put_contents($path, $before."\n// edited\n");

        expect($cache()->get($key))->toBeNull();
    } finally {
        file_put_contents($path, (string) $before);
        array_map('unlink', glob($dir.'/*') ?: []);
        @unlink($dir.'/.gitignore');
        @rmdir($dir);
    }
})->with([
    'the concern a descended body is written in' => ['descended', 'app/Queries/Concerns/FiltersExports.php'],
    'the concern a folded return is written in' => ['folded', 'modules/Billing/Concerns/NamesExportFacets.php'],
    'the concern the traced action itself is written in' => ['descended', 'app/Http/Controllers/Concerns/ListsExports.php'],
])->group('fixture');
