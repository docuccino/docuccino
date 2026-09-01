<?php

declare(strict_types=1);

/*
 * The guard behind the stub's documented placeholders.
 *
 * A stub is only customisable in practice if its variables are written down, and the reference page's
 * table is hand-maintained: a placeholder added to the stub and forgotten there is a variable nobody
 * can use, and one removed from the stub leaves the page describing a value that never arrives. The
 * stub is the source of truth and this reads it.
 */

/** @return list<string> */
function packagedStubPlaceholders(): array
{
    $stub = (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/stubs/version-change.stub');

    preg_match_all('/\{\{\s*([a-z]+)\s*\}\}/', $stub, $matches);

    $names = array_values(array_unique($matches[1]));
    sort($names, SORT_STRING);

    return $names;
}

/** The placeholders the commands reference tables, read out of its `{{ … }}` code spans. */
function documentedStubPlaceholders(): array
{
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/website/src/content/docs/laravel/reference/commands.md');

    preg_match_all('/^\| `\{\{ ([a-z]+) \}\}` \|/m', $page, $matches);

    $names = array_values(array_unique($matches[1]));
    sort($names, SORT_STRING);

    return $names;
}

it('reads a plausible number of placeholders out of the stub', function (): void {
    // A reader that stopped matching would make the comparison below vacuous in both directions.
    expect(packagedStubPlaceholders())->toHaveCount(6);
});

it('documents every placeholder the stub carries, and none it does not', function (): void {
    expect(documentedStubPlaceholders())->toBe(packagedStubPlaceholders());
});
