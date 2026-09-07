<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;

/*
 * The guard behind the built-in error-name catalogue. Two pages state it — the errors page as a table,
 * the extension-authoring page as a sentence naming what you would be overriding — and they had already
 * drifted apart, one listing six of the ten. FrameworkExceptionTable is the source of truth, and both
 * pages are read back as a status => name map, so a status added, a phrase reworded or a page left short
 * fails here rather than shipping as a catalogue that disagrees with itself.
 */

/** Every status the framework tier speaks for, with the component name it publishes under. */
function builtInErrorComponentNames(): array
{
    $names = [];
    foreach (FrameworkExceptionTable::reasonPhrases() as [$status, $_phrase]) {
        $name = FrameworkExceptionTable::componentName($status);

        expect($name)->not->toBeNull($status.' has a reason phrase but no component name');

        $names[$status] = (string) $name;
    }

    ksort($names, SORT_STRING);

    return $names;
}

function docsPage(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/website/src/content/docs/'.$relative);
}

/**
 * The errors page's catalogue table, read back as status => component name. One row may carry several of
 * each — the statuses whose only story is "an unrecognized exception" share a row — so the two columns
 * are zipped rather than assumed to hold one apiece.
 */
function catalogueTableNames(string $page): array
{
    $header = '| Status | Component | Produced for |';
    $start = strpos($page, $header);

    expect($start)->not->toBeFalse('the errors page no longer has a catalogue table');

    $names = [];
    $lines = explode("\n", substr($page, (int) $start));
    array_shift($lines);

    foreach ($lines as $line) {
        if (! str_starts_with($line, '|')) {
            break;
        }

        $columns = array_map(trim(...), explode('|', trim($line, '|')));
        if (count($columns) < 2 || str_starts_with($columns[0], '---')) {
            continue;
        }

        preg_match_all('/`([^`]+)`/', $columns[0], $statuses);
        preg_match_all('/`([^`]+)`/', $columns[1], $components);

        expect(count($statuses[1]))->toBe(
            count($components[1]),
            'a catalogue row pairs '.count($statuses[1]).' statuses with '.count($components[1]).' names: '.$line,
        );

        foreach ($statuses[1] as $index => $status) {
            $names[$status] = $components[1][$index];
        }
    }

    ksort($names, SORT_STRING);

    return $names;
}

/** The names the extension-authoring page tells an author they would be overriding. */
function overridableNamesSentence(string $page): array
{
    $start = strpos($page, "Docuccino's own tiers name Laravel's errors");

    expect($start)->not->toBeFalse('the extension-authoring page no longer names the built-ins');

    $end = strpos($page, '.', (int) $start);
    preg_match_all('/`([A-Za-z]+)`/', substr($page, (int) $start, (int) $end - (int) $start), $matches);

    $names = $matches[1];
    sort($names);

    return $names;
}

it('reads a plausible catalogue, and reads only component names', function (): void {
    // A table that stopped being readable would make every assertion below vacuous.
    $names = builtInErrorComponentNames();

    expect(count($names))->toBeGreaterThanOrEqual(8)
        ->and($names)->toMatchArray(['404' => 'NotFound', '422' => 'UnprocessableEntity'])
        ->and(array_filter($names, static fn (string $n): bool => preg_match('/^[A-Z][A-Za-z]+$/', $n) !== 1))->toBe([]);
});

it('holds the errors page table to the names the code publishes, in both directions', function (): void {
    expect(catalogueTableNames(docsPage('laravel/documenting/errors.mdx')))->toBe(builtInErrorComponentNames());
});

it('holds the extension-authoring list to the same set', function (): void {
    $published = array_values(builtInErrorComponentNames());
    sort($published);

    expect(overridableNamesSentence(docsPage('extending/extension-authoring.mdx')))->toBe($published);
});

/**
 * The errors page's OTHER catalogue: the framework-defaults table, which lists the exceptions rather
 * than the statuses. It is a third hand-maintained statement of the same source of truth and it had
 * been left short — the page named one `HttpException` subclass while the table mapped sixteen — so it
 * is read back the same way: exception => status, in both directions.
 *
 * @return array<string, string>
 */
function frameworkDefaultsTableStatuses(string $page): array
{
    $header = '| Exception | Status | Body |';
    $start = strpos($page, $header);

    expect($start)->not->toBeFalse('the errors page no longer has a framework-defaults table');

    $rows = [];
    $lines = explode("\n", substr($page, (int) $start));
    array_shift($lines);

    foreach ($lines as $line) {
        if (! str_starts_with($line, '|')) {
            break;
        }

        $columns = array_map(trim(...), explode('|', trim($line, '|')));
        if (count($columns) < 3 || str_starts_with($columns[0], '---')) {
            continue;
        }

        if (preg_match('/^`([^`]+)`$/', $columns[0], $exception) !== 1) {
            continue;
        }

        preg_match('/`([^`]+)`/', $columns[1], $status);
        $rows[$exception[1]] = $status[1] ?? '';
    }

    ksort($rows, SORT_STRING);

    return $rows;
}

/**
 * The same map off the table itself.
 *
 * @return array<string, string>
 */
function frameworkDefaultsStatuses(): array
{
    $rows = [];
    foreach (FrameworkExceptionTable::exceptions() as $fqcn) {
        $facts = FrameworkExceptionTable::match($fqcn);

        expect($facts)->not->toBeNull($fqcn.' is listed by exceptions() but matches nothing');

        $rows[$fqcn] = (string) $facts['status'];
    }

    ksort($rows, SORT_STRING);

    return $rows;
}

it('holds the framework-defaults table to the exceptions the code maps, in both directions', function (): void {
    $documented = frameworkDefaultsTableStatuses(docsPage('laravel/documenting/errors.mdx'));

    // A table that stopped parsing would satisfy an equality against an empty map. Both sides are real.
    expect(count($documented))->toBeGreaterThanOrEqual(20)
        ->and($documented)->toBe(frameworkDefaultsStatuses());
});

it('says which body shape each documented exception publishes', function (): void {
    // The third column is the only place the page states the `errors` map, and the map is the one thing
    // that differs between these rows. Stated here from the contract — a field-keyed `errors` object is
    // a validator's output — rather than read off the code, so a row that stopped claiming it fails.
    $page = docsPage('laravel/documenting/errors.mdx');
    $validation = [];
    foreach (FrameworkExceptionTable::exceptions() as $fqcn) {
        $facts = FrameworkExceptionTable::match($fqcn);
        if ($facts !== null && $facts['validation']) {
            $validation[] = $fqcn;
        }
    }

    expect($validation)->toBe(['Illuminate\\Validation\\ValidationException']);

    foreach (array_keys(frameworkDefaultsTableStatuses($page)) as $fqcn) {
        $row = strstr($page, '| `'.$fqcn.'` |');
        $body = explode('|', (string) $row)[3] ?? '';
        $shape = in_array($fqcn, $validation, true) ? '`{ message, errors }`' : '`{ message }`';

        expect(trim($body))->toStartWith($shape);
    }
});
