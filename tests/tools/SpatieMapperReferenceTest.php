<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;

/*
 * The guard behind the Spatie Data page's list of recognised name mappers. The page states the set as
 * prose, and a set nobody checks is a promise to remember — this one was a mapper short for as long as
 * the reflector's table was, so the page confirmed the bug rather than exposing it. It reads the table
 * instead: a mapper the reflector learns has to be written down before the suite goes green.
 *
 * Read off the reflector's own constant, not off the installed package, so the page states what
 * Docuccino recognises rather than what one resolved version of spatie/laravel-data happens to ship.
 * Whether the TABLE is short of the package is the adapter suite's question, not the page's.
 */

/** @return list<string> */
function recognisedMapperNames(): array
{
    /** @var array<string, string> $mappers */
    $mappers = (new ReflectionClass(DataClassReflector::class))->getConstant('MAPPERS');

    $names = array_map(
        static fn (string $fqcn): string => substr((string) strrchr($fqcn, '\\'), 1),
        array_keys($mappers),
    );
    sort($names);

    return array_values($names);
}

/** @return list<string> */
function referencedMapperNames(): array
{
    $page = (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/packages/spatie-data.mdx',
    );

    preg_match_all('/`(\w+CaseMapper)`/', $page, $matches);

    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

it('names every mapper the reflector recognises, and none it does not', function (): void {
    expect(referencedMapperNames())->toBe(recognisedMapperNames());
});

it('finds a plausible number of mappers on both sides', function (): void {
    // A page scan that stopped matching, or a constant read that came back empty, would make the
    // comparison above two empty lists agreeing with each other.
    expect(count(recognisedMapperNames()))->toBeGreaterThanOrEqual(6);
});
