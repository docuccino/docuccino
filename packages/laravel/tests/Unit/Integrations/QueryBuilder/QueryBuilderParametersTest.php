<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Laravel\Integrations\QueryBuilder\QbEntry;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderFacts;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParameters;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryParameterSpec;

/**
 * Dataset coverage over the representation-policy expression of every recovered fact kind, in BOTH
 * the default (bracketed / comma) and alternative (deepObject / array) styles — the semantic facts
 * are identical, only the OAS expression changes (design §Representation policies).
 */
function factsWith(callable $mutate): QueryBuilderFacts
{
    $facts = new QueryBuilderFacts;
    $mutate($facts);

    return $facts;
}

/**
 * @return array<string, QueryParameterSpec>
 */
function specsByName(array $specs): array
{
    $byName = [];
    foreach ($specs as $spec) {
        $byName[$spec->name] = $spec;
    }

    return $byName;
}

function bracketedPolicy(): RepresentationPolicy
{
    return new RepresentationPolicy;
}

function deepObjectPolicy(): RepresentationPolicy
{
    return new RepresentationPolicy(filterStyle: 'deepObject', listStyle: 'array');
}

it('expresses filters as flat bracketed params by default, with kind descriptions', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [new QbEntry('status', 'exact'), new QbEntry('email', 'partial')];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect(array_keys($byName))->toBe(['filter[status]', 'filter[email]']);
    expect($byName['filter[status]']->schema)->toBe(['type' => 'string'])
        ->and($byName['filter[status]']->description)->toBe('Exact-match filter')
        ->and($byName['filter[email]']->description)->toBe('Partial-match filter');
});

it('expresses filters as a single deepObject param under the deepObject policy', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [new QbEntry('status', 'exact'), new QbEntry('email', 'partial')];
    });

    $specs = (new QueryBuilderParameters)->build($facts, deepObjectPolicy());

    expect($specs)->toHaveCount(1);
    expect($specs[0]->name)->toBe('filter')
        ->and($specs[0]->style)->toBe('deepObject')
        ->and($specs[0]->explode)->toBeTrue()
        ->and($specs[0]->schema)->toBe([
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'Exact-match filter'],
                'email' => ['type' => 'string', 'description' => 'Partial-match filter'],
            ],
        ]);
});

it('expresses sort as a comma string with a default by default', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->sorts = [new QbEntry('name', 'default'), new QbEntry('created_at', 'field')];
        $f->defaultSorts = ['name'];
    });

    $specs = (new QueryBuilderParameters)->build($facts, bracketedPolicy());

    expect($specs)->toHaveCount(1);
    expect($specs[0]->name)->toBe('sort')
        ->and($specs[0]->schema)->toBe(['type' => 'string', 'default' => 'name'])
        ->and($specs[0]->style)->toBeNull()
        ->and($specs[0]->description)->toContain('prefix `-` for descending');
});

it('expresses sort as an exploded array with an enum incl. the descending forms under the array policy', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->sorts = [new QbEntry('name', 'default'), new QbEntry('created_at', 'field')];
        $f->defaultSorts = ['name'];
    });

    $specs = (new QueryBuilderParameters)->build($facts, deepObjectPolicy());

    expect($specs[0]->name)->toBe('sort')
        ->and($specs[0]->style)->toBe('form')
        ->and($specs[0]->explode)->toBeFalse()
        ->and($specs[0]->schema)->toBe([
            'type' => 'array',
            'items' => ['type' => 'string', 'enum' => ['name', '-name', 'created_at', '-created_at']],
            'default' => ['name'],
        ]);
});

it('expresses include as a comma string by default and an exploded array under the array policy', function (RepresentationPolicy $policy, array $expected): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->includes = [new QbEntry('author', 'default'), new QbEntry('comments', 'default')];
    });

    $specs = (new QueryBuilderParameters)->build($facts, $policy);

    expect($specs[0]->name)->toBe('include')
        ->and($specs[0]->schema)->toBe($expected);
})->with([
    'comma' => [new RepresentationPolicy, ['type' => 'string']],
    'array' => [new RepresentationPolicy(listStyle: 'array'), [
        'type' => 'array',
        'items' => ['type' => 'string', 'enum' => ['author', 'comments']],
    ]],
]);

it('groups sparse fields into fields[type] params by default', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->fields = [new QbEntry('articles.title', 'field'), new QbEntry('articles.body', 'field'), new QbEntry('author.name', 'field')];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect(array_keys($byName))->toBe(['fields[articles]', 'fields[author]']);
    expect($byName['fields[articles]']->schema)->toBe(['type' => 'string'])
        ->and($byName['fields[articles]']->description)->toBe('Comma-separated fields: title, body.');
});

it('groups sparse fields into a single deepObject fields param under the deepObject policy', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->fields = [new QbEntry('articles.title', 'field'), new QbEntry('author.name', 'field')];
    });

    $specs = (new QueryBuilderParameters)->build($facts, deepObjectPolicy());

    expect($specs)->toHaveCount(1);
    expect($specs[0]->name)->toBe('fields')
        ->and($specs[0]->style)->toBe('deepObject')
        ->and($specs[0]->schema['type'])->toBe('object')
        ->and(array_keys($specs[0]->schema['properties']))->toBe(['articles', 'author']);
});

it('adds page/per_page for length + simple pagination and cursor/per_page for cursor', function (string $kind, ?int $perPage, array $expectedNames, int $expectedPerPageDefault): void {
    $facts = factsWith(function (QueryBuilderFacts $f) use ($kind, $perPage): void {
        $f->paginates = true;
        $f->paginationKind = $kind;
        $f->perPage = $perPage;
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect(array_keys($byName))->toBe($expectedNames);
    expect($byName['per_page']->schema)->toBe(['type' => 'integer', 'default' => $expectedPerPageDefault, 'minimum' => 1]);
})->with([
    'length with recovered per-page' => ['length', 25, ['page', 'per_page'], 25],
    'simple falling back to default per-page' => ['simple', null, ['page', 'per_page'], 15],
    'cursor' => ['cursor', 50, ['cursor', 'per_page'], 50],
]);

it('contributes nothing when no facts were recovered', function (): void {
    expect((new QueryBuilderParameters)->build(new QueryBuilderFacts, bracketedPolicy()))->toBe([]);
});
