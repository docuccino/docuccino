<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateConfig;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateFacts;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateParameters;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/**
 * The pure builder that turns a config + recovered facts into the JSON:API pagination query params.
 * Dataset-driven over the length/simple/cursor modes, the renamed-parameter path, the call-site
 * size overrides, and the no-terminal degradation (contributes nothing).
 */
function paginateFacts(bool $paginates = true, ?int $max = null, ?int $default = null): JsonApiPaginateFacts
{
    $facts = new JsonApiPaginateFacts;
    $facts->paginates = $paginates;
    $facts->maxResultsOverride = $max;
    $facts->defaultSizeOverride = $default;

    return $facts;
}

/**
 * @param  list<QueryParameterSpec>  $specs
 * @return array<string, QueryParameterSpec>
 */
function paginateByName(array $specs): array
{
    $byName = [];
    foreach ($specs as $spec) {
        $byName[$spec->name] = $spec;
    }

    return $byName;
}

it('contributes nothing when the chain reaches no jsonPaginate terminal', function (): void {
    expect((new JsonApiPaginateParameters)->build(new JsonApiPaginateConfig, paginateFacts(paginates: false)))->toBe([]);
});

it('emits page[number]/page[size] for length and simple modes', function (string $mode): void {
    $config = new JsonApiPaginateConfig(defaultSize: 30, maxResults: 30, mode: $mode);

    $byName = paginateByName((new JsonApiPaginateParameters)->build($config, paginateFacts()));

    expect(array_keys($byName))->toBe(['page[number]', 'page[size]']);
    expect($byName['page[number]']->schema)->toBe(['type' => 'integer', 'default' => 1, 'minimum' => 1])
        ->and($byName['page[size]']->schema)->toBe(['type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 30]);
})->with([
    'length' => [JsonApiPaginateConfig::MODE_LENGTH],
    'simple' => [JsonApiPaginateConfig::MODE_SIMPLE],
]);

it('emits page[cursor]/page[size] under cursor mode (no page[number])', function (): void {
    $config = new JsonApiPaginateConfig(mode: JsonApiPaginateConfig::MODE_CURSOR);

    $byName = paginateByName((new JsonApiPaginateParameters)->build($config, paginateFacts()));

    expect(array_keys($byName))->toBe(['page[cursor]', 'page[size]']);
    expect($byName['page[cursor]']->schema)->toBe(['type' => 'string']);
});

it('honours renamed parameter names from config', function (): void {
    $config = new JsonApiPaginateConfig(
        pageParameter: 'p',
        numberParameter: 'num',
        sizeParameter: 'sz',
    );

    $byName = paginateByName((new JsonApiPaginateParameters)->build($config, paginateFacts()));

    expect(array_keys($byName))->toBe(['p[num]', 'p[sz]']);
});

it('lets call-site jsonPaginate($max, $default) arguments override the config sizes', function (): void {
    $config = new JsonApiPaginateConfig(defaultSize: 30, maxResults: 30);

    $byName = paginateByName((new JsonApiPaginateParameters)->build($config, paginateFacts(max: 100, default: 10)));

    expect($byName['page[size]']->schema)->toBe(['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100]);
});
