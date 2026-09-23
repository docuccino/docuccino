<?php

declare(strict_types=1);

/*
 * The schema host's landing page against the schemas it hosts.
 *
 * `spec/index.html` is the page a third party reaches when they resolve an `$id`, and its version
 * table is hand-written — the kind of full set this repository has already shipped short elsewhere.
 * It listed 1.0 alone while `spec/uir/` held two versions, so the page told a reader the second one
 * did not exist.
 *
 * The rule is stated here rather than asked of the page: every schema file under `spec/uir/` is
 * published forever at its own URL, so every one of them is linked, and exactly one version is marked
 * current — the newest. Read off the directory, because that is the source of truth the sync tools
 * and the drift guards all read.
 *
 * The page is not the only hand-written catalogue of the family — `spec/README.md` carries the same
 * table in the split repository's front matter — so both are held to the same directory. One guard
 * over one of them would have been the defect again, one file along.
 *
 * It lives in the PHP suite rather than in the website build for the reason the `schema-copies` CI
 * job exists: a guard that only runs where the site is built is a guard a standalone deploy skips.
 */

/**
 * Every schema the host publishes, as the site-relative URL it is served at.
 *
 * A projection of {@see publishedSchemaFiles()} rather than a second read of the same directory: the
 * two scanned `spec/uir/` separately, and a guard and a sync tool that each decide for themselves
 * what counts as a schema file are one edit away from disagreeing about it — which is exactly what
 * happened to the two sync tools.
 *
 * @return list<string>
 */
function publishedSchemaUrls(): array
{
    return array_map(
        static fn (string $name): string => '/uir/'.$name,
        array_keys(publishedSchemaFiles()),
    );
}

/**
 * The URLs $page fails to link. The predicate the guard turns on, kept separate from the file so the
 * refusal below can be executed on a page written to fail it.
 *
 * @param  list<string>  $urls
 * @return list<string>
 */
function schemaUrlsMissingFrom(string $page, array $urls): array
{
    return array_values(array_filter(
        $urls,
        static fn (string $url): bool => ! str_contains($page, 'href="'.$url.'"'),
    ));
}

/**
 * The version each `<tr>` of the page marks current, as a list — so "exactly one, and the newest" is
 * one assertion rather than a search.
 *
 * @return list<string>
 */
function versionsMarkedCurrent(string $page): array
{
    preg_match_all('~<tr>(.*?)</tr>~s', $page, $rows);

    $current = [];
    foreach ($rows[1] as $row) {
        if (! str_contains($row, '<td>Current</td>')) {
            continue;
        }

        if (preg_match('~<td>(\d+\.\d+)</td>~', $row, $version) === 1) {
            $current[] = $version[1];
        }
    }

    return $current;
}

function specLandingPage(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/spec/index.html');
}

it('links every schema the host publishes', function (): void {
    $urls = publishedSchemaUrls();

    // A scan over an empty directory would report the page complete. Three versions today, and 2.0 is
    // two files.
    expect(count($urls))->toBeGreaterThanOrEqual(4)
        ->and($urls)->toContain('/uir/2.0/extension.schema.json')
        ->and(schemaUrlsMissingFrom(specLandingPage(), $urls))->toBe([]);
});

it('lists every schema the host publishes in the split repository README', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/spec/README.md');

    // Spelled as the full `$id` there rather than as a site-relative href, which is what the README's
    // table is about — so the same directory read answers a differently-written list.
    $missing = array_values(array_filter(
        publishedSchemaUrls(),
        static fn (string $url): bool => ! str_contains($readme, 'https://spec.docuccino.app'.$url),
    ));

    expect(count(publishedSchemaUrls()))->toBeGreaterThanOrEqual(4)
        ->and($missing)->toBe([]);
});

it('marks exactly one version current, and it is the newest published', function (): void {
    $versions = [];
    foreach (publishedSchemaUrls() as $url) {
        $versions[explode('/', $url)[2]] = true;
    }

    $names = array_keys($versions);
    usort($names, version_compare(...));

    expect(versionsMarkedCurrent(specLandingPage()))->toBe([(string) end($names)]);
});

/*
 * Both predicates executed against pages written to fail them — "the table is complete" is exactly
 * what was true of a table listing one version out of two.
 */
it('calls a page that has dropped a version short', function (): void {
    $page = specLandingPage();
    $short = str_replace('href="/uir/1.1/schema.json"', 'href="/uir/1.1/"', $page);

    expect($short)->not->toBe($page)
        ->and(schemaUrlsMissingFrom($short, publishedSchemaUrls()))->toBe(['/uir/1.1/schema.json'])
        // And a page missing the file a version added, not only the version itself.
        ->and(schemaUrlsMissingFrom(
            str_replace('href="/uir/2.0/extension.schema.json"', 'href="/uir/2.0/"', $page),
            publishedSchemaUrls(),
        ))->toBe(['/uir/2.0/extension.schema.json']);
});

it('calls a page that marks the wrong number of versions current wrong', function (): void {
    expect(versionsMarkedCurrent('<tr><td>1.0</td><td>x</td><td>Current</td></tr><tr><td>2.0</td><td>y</td><td>Current</td></tr>'))
        ->toBe(['1.0', '2.0'])
        ->and(versionsMarkedCurrent('<tr><td>2.0</td><td>y</td><td>Superseded</td></tr>'))->toBe([]);
});
