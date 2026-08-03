<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Integrations\QueryBuilder\QbEntry;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderFacts;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParameters;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/**
 * The crown jewel (design §Phase 4 — the Scramble-Pro-beater), end-to-end on the REAL engine:
 * the spike-B fixture builds its allow-lists inside a `UserIndexQuery` helper two calls deep and
 * paginates behind a custom `paginateList` terminal, with ZERO doc annotations. This asserts the
 * real PHPStan/Larastan engine recovers those facts through the trace boundary AND that the QB
 * integration's parameter builder turns them into the right query parameters under both
 * representation styles. Recovery is real; only the fold-to-facts glue is test code.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * @return array<string, mixed>
 */
function realQbHarvest(): array
{
    return FixtureRunner::traceQb(
        'app/Http/Controllers/UserListController.php',
        'App\\Http\\Controllers\\UserListController',
        'listUsers',
    );
}

function qbEntryFromRendered(string $rendered): QbEntry
{
    if (str_starts_with($rendered, "'")) {
        return new QbEntry(trim($rendered, "'"), 'default');
    }

    preg_match("/::(\\w+)\\('([^']*)'/", $rendered, $matches);

    return new QbEntry($matches[2] ?? $rendered, $matches[1] ?? 'default');
}

function factsFromRealHarvest(): QueryBuilderFacts
{
    $harvest = realQbHarvest();
    $facts = new QueryBuilderFacts;

    $facts->filters = array_map(qbEntryFromRendered(...), $harvest['filters']);
    $facts->sorts = array_map(qbEntryFromRendered(...), $harvest['sorts']);
    $facts->defaultSorts = array_map(static fn (string $d): string => trim($d, "'"), $harvest['default']);
    $facts->paginates = (bool) $harvest['paginates'];
    $facts->perPage = $harvest['perPage'];
    $facts->paginationKind = 'length';

    return $facts;
}

it('recovers the allow-lists + pagination through a two-deep helper on the real engine', function (): void {
    $harvest = realQbHarvest();

    expect($harvest['filters'])->toBe([
        "'name'",
        "AllowedFilter::exact('status')",
        "AllowedFilter::partial('email')",
    ]);
    expect($harvest['sorts'])->toBe(["'name'", "'created_at'"])
        ->and($harvest['default'])->toBe(["'name'"])
        ->and($harvest['paginates'])->toBeTrue()
        ->and($harvest['perPage'])->toBe(25);
})->group('fixture');

it('turns the real-engine harvest into bracketed query parameters', function (): void {
    $specs = (new QueryBuilderParameters)->build(factsFromRealHarvest(), new RepresentationPolicy);

    $byName = [];
    foreach ($specs as $spec) {
        $byName[$spec->name] = $spec;
    }

    expect(array_keys($byName))->toEqualCanonicalizing([
        'filter[name]', 'filter[status]', 'filter[email]', 'sort', 'page', 'per_page',
    ]);
    expect($byName['filter[status]']->description)->toBe('Exact-match filter')
        ->and($byName['sort']->schema['default'])->toBe('name')
        ->and($byName['per_page']->schema['default'])->toBe(25);
})->group('fixture');

it('turns the real-engine harvest into a deepObject filter param under the deepObject policy', function (): void {
    $specs = (new QueryBuilderParameters)->build(
        factsFromRealHarvest(),
        new RepresentationPolicy(filterStyle: 'deepObject', listStyle: 'array'),
    );

    $filter = array_values(array_filter($specs, static fn (QueryParameterSpec $s): bool => $s->name === 'filter'));

    expect($filter)->toHaveCount(1);
    expect($filter[0]->style)->toBe('deepObject')
        ->and($filter[0]->explode)->toBeTrue()
        ->and(array_keys($filter[0]->schema['properties']))->toBe(['name', 'status', 'email']);
})->group('fixture');
