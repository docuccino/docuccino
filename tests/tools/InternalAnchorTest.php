<?php

declare(strict_types=1);

/*
 * The guard that keeps the docs site's own cross-references pointing at something. Starlight builds a
 * page whose link says `#the-section-that-moved` exactly as happily as one whose link resolves, so a
 * renamed or deleted heading takes every link to it down in silence — the reader lands at the top of the
 * page and never learns what they were sent to read. Every other reference guard here compares a page
 * against the source of truth it describes; this one compares the pages against each other.
 *
 * It reads only what the pages themselves state — a markdown link whose href is site-absolute or a bare
 * fragment — and resolves it against the headings of the page it names, slugified the way Starlight's
 * own slugger does. External links are nobody's to check here.
 */

/** Every docs page, as its published route => its file. */
function anchorPages(): array
{
    $root = dirname(__DIR__, 2).'/website/src/content/docs';
    $pages = [];

    /** @var iterable<SplFileInfo> $entries */
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($entries as $entry) {
        if (! $entry->isFile() || ! in_array($entry->getExtension(), ['md', 'mdx'], true)) {
            continue;
        }

        $route = '/'.preg_replace('/\.(md|mdx)$/', '', substr($entry->getPathname(), strlen($root) + 1));
        $route = (string) preg_replace('#/index$#', '', (string) $route);
        $route = $route === '' ? '/' : rtrim($route, '/').'/';

        $pages[$route] = $entry->getPathname();
    }

    ksort($pages);

    return $pages;
}

/**
 * A heading's anchor, the way github-slugger (which Starlight uses) makes one: the rendered text,
 * lower-cased, with everything but letters, digits, spaces, `_` and `-` dropped, and spaces hyphenated.
 */
function anchorSlug(string $heading): string
{
    $text = str_replace(['&amp;', '&lt;', '&gt;', '&quot;', '&#39;', '&nbsp;'], ['&', '<', '>', '"', "'", ' '], $heading);
    // The rendered text, not the markup: inline code, links and emphasis all contribute their contents.
    $text = (string) preg_replace('/`([^`]*)`/', '$1', $text);
    $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
    $text = (string) preg_replace('/\*{1,3}/', '', $text);
    $text = mb_strtolower(trim($text));

    return str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N} _-]+/u', '', $text));
}

/**
 * The anchors a page offers. Fenced code is skipped, because `#[Attribute]` on its own line inside a PHP
 * block is not a heading; an explicit `{#id}` wins over the slug, since that is what the page publishes.
 *
 * @return list<string>
 */
function anchorsOffered(string $path): array
{
    $fenced = false;
    $anchors = [];

    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
            $fenced = ! $fenced;

            continue;
        }
        if ($fenced || preg_match('/^(#{1,6})\s+(.*?)\s*$/', $line, $heading) !== 1) {
            continue;
        }

        $anchors[] = preg_match('/\{#([^}]+)\}\s*$/', $heading[2], $explicit) === 1
            ? $explicit[1]
            : anchorSlug($heading[2]);
    }

    return $anchors;
}

/**
 * Every internal anchored link the pages state, as `[page route, href]`.
 *
 * @return list<array{string, string}>
 */
function anchoredLinks(): array
{
    $links = [];

    foreach (anchorPages() as $route => $path) {
        preg_match_all('/\]\((\/[^)\s]*#[^)\s]*|#[^)\s]+)\)/', (string) file_get_contents($path), $matches);
        foreach ($matches[1] as $href) {
            $links[] = [$route, $href];
        }
    }

    return $links;
}

it('points every internal anchor at a heading that exists', function (): void {
    $anchors = array_map(anchorsOffered(...), anchorPages());

    $rotten = [];
    foreach (anchoredLinks() as [$from, $href]) {
        if (str_starts_with($href, '#')) {
            [$target, $anchor] = [$from, substr($href, 1)];
        } else {
            [$page, $anchor] = explode('#', $href, 2);
            $target = rtrim($page, '/').'/';
        }

        $rotten[] = match (true) {
            ! isset($anchors[$target]) => $from.' links to '.$href.', and no page publishes '.$target,
            ! in_array($anchor, $anchors[$target], true) => $from.' links to '.$href.', and that page has no such heading',
            default => null,
        };
    }

    expect(array_values(array_filter($rotten)))->toBe([]);
});

it('is reading pages, headings and links rather than matching nothing', function (): void {
    // The plausible minimums: a scan that stopped recognising any of the three would otherwise pass
    // forever, and this guard is the only thing asking. Well under what the site holds today.
    $anchors = array_merge(...array_values(array_map(anchorsOffered(...), anchorPages())));

    expect(count(anchorPages()))->toBeGreaterThan(25)
        ->and(count($anchors))->toBeGreaterThan(200)
        ->and(count(anchoredLinks()))->toBeGreaterThan(300);
});

it('slugifies a heading the way the site does', function (string $heading, string $slug): void {
    // Stated as rows rather than read back off a page, because a slugifier that agreed with itself would
    // pass the guard above on anchors nobody can reach. Each row is a shape the docs actually use.
    expect(anchorSlug($heading))->toBe($slug);
})->with([
    'plain words' => ['Framework defaults', 'framework-defaults'],
    'inline code' => ['`error_responses`', 'error_responses'],
    'an attribute in code' => ['`#[ErrorComponent]`', 'errorcomponent'],
    'punctuation and an entity' => ['Implicit responses (middleware, bindings &amp; validation)', 'implicit-responses-middleware-bindings--validation'],
    'a trailing parenthetical' => ['The viewer route (opt-in)', 'the-viewer-route-opt-in'],
    'emphasis' => ['Your *real* error shapes', 'your-real-error-shapes'],
]);
