<?php

declare(strict_types=1);

/*
 * The guard that keeps the docs site's own cross-references pointing at something. Starlight builds a
 * page whose link says `#the-section-that-moved` exactly as happily as one whose link resolves, so a
 * renamed or deleted heading takes every link to it down in silence — the reader lands at the top of the
 * page and never learns what they were sent to read. Every other reference guard here compares a page
 * against the source of truth it describes; this one compares the pages against each other.
 *
 * It reads only what the pages themselves state — a link whose href is site-absolute or a bare fragment,
 * written either as markdown or as a component's `href` attribute — and resolves it against the headings
 * of the page it names, slugified the way Starlight's own slugger does. External links are nobody's to
 * check here.
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
 * The anchors a page offers. Fenced code is skipped, because `#[Attribute]` on its own line inside a PHP
 * block is not a heading; an explicit `{#id}` wins over the slug, since that is what the page publishes.
 *
 * Repeats are numbered the way github-slugger numbers them — the second heading slugifying to `security`
 * publishes `security-1`, the third `security-2` — because a slug set without that names anchors the site
 * never serves and, worse, clears a link that lands on the wrong heading. The attributes reference has a
 * `## Security` section holding a `### #[Security]` entry, so this is a page the site really publishes,
 * not a shape only the generated changelog reaches.
 *
 * @return list<string>
 */
function anchorsOffered(string $path): array
{
    $fenced = false;
    $anchors = [];
    $taken = [];

    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
            $fenced = ! $fenced;

            continue;
        }
        if ($fenced || preg_match('/^(#{1,6})\s+(.*?)\s*$/', $line, $heading) !== 1) {
            continue;
        }

        $slug = preg_match('/\{#([^}]+)\}\s*$/', $heading[2], $explicit) === 1
            ? $explicit[1]
            : docsAnchorSlug($heading[2]);

        $anchor = $slug;
        while (array_key_exists($anchor, $taken)) {
            $anchor = $slug.'-'.++$taken[$slug];
        }

        $taken[$anchor] = 0;
        $anchors[] = $anchor;
    }

    return $anchors;
}

/**
 * Every internal anchored link the pages state, as `[page route, href]`.
 *
 * Two forms, because a page states links two ways and the docblock above promises both. Markdown
 * `](…)` is the common one; a Starlight component takes its target as an `href="…"` attribute instead,
 * which a markdown-only scan reads straight past — seven `<LinkCard>` targets sat unchecked that way,
 * and a component link rots exactly as silently as a prose one.
 *
 * @return list<array{string, string}>
 */
function anchoredLinks(): array
{
    $links = [];

    foreach (anchorPages() as $route => $path) {
        $source = (string) file_get_contents($path);

        foreach (['/\]\((\/[^)\s]*#[^)\s]*|#[^)\s]+)\)/', '/\bhref=["\'](\/[^"\'\s]*#[^"\'\s]*|#[^"\'\s]+)["\']/'] as $pattern) {
            preg_match_all($pattern, $source, $matches);
            foreach ($matches[1] as $href) {
                $links[] = [$route, $href];
            }
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
    // pass the guard above on anchors nobody can reach. Each row is a shape the docs actually use, plus
    // the two the site's two slugifiers used to disagree about before they became one.
    expect(docsAnchorSlug($heading))->toBe($slug);
})->with([
    'plain words' => ['Framework defaults', 'framework-defaults'],
    'inline code' => ['`error_responses`', 'error_responses'],
    'an attribute in code' => ['`#[ErrorComponent]`', 'errorcomponent'],
    'punctuation and an entity' => ['Implicit responses (middleware, bindings &amp; validation)', 'implicit-responses-middleware-bindings--validation'],
    'a trailing parenthetical' => ['The viewer route (opt-in)', 'the-viewer-route-opt-in'],
    'emphasis' => ['Your *real* error shapes', 'your-real-error-shapes'],
    // github-slugger's character class keeps both, so the anchor a reader lands on keeps them too. The
    // implementation that stripped them would have sent every link to such a heading to the top of the page.
    'an underscore survives' => ['The api_version key', 'the-api_version-key'],
    'a non-ASCII letter survives' => ['Café responses', 'café-responses'],
]);

it('numbers a repeated heading the way github-slugger does', function (): void {
    // The rule stated against a page the site really publishes. `## Security` and the `### #[Security]`
    // entry inside it both slugify to `security`, and one page already links at that anchor — so a slug
    // set that offered `security` twice would clear a link to either while the site serves only the
    // first. Numbering is what makes the two addressable apart.
    $attributes = anchorsOffered(anchorPages()['/laravel/reference/attributes/']);
    $changelog = anchorsOffered(anchorPages()['/changelog/']);

    expect($attributes)->toContain('security')
        ->and($attributes)->toContain('security-1')
        // The invariant the numbering exists for: what a page offers is a SET, on every page. A counter
        // that reset per heading, or numbered from the wrong base, breaks this and nothing else would say so.
        ->and(array_unique($attributes))->toHaveCount(count($attributes))
        ->and(array_unique($changelog))->toHaveCount(count($changelog))
        // …and the series really continues past the first repeat, rather than `-1` being the only branch
        // anything in the tree exercises.
        ->and(array_filter($changelog, static fn (string $a): bool => str_ends_with($a, '-2')))->not->toBeEmpty();
});

it('resolves routes the way the site actually publishes them', function (): void {
    // `anchorPages()` states two facts about the deployment rather than reading them: every route ends in
    // a slash, and no path prefix sits in front of it. Both are Astro defaults today, and both are one
    // config key from being false — `base: '/docuccino'` would move every URL and `trailingSlash` decides
    // whether `/uir` or `/uir/` is the published one, either of which would leave this guard resolving
    // links against routes nobody serves while still passing. So the assumptions are asserted where they
    // are made: a config that takes a position on either fails here and says which.
    $config = (string) file_get_contents(dirname(__DIR__, 2).'/website/astro.config.mjs');
    $uncommented = (string) preg_replace('#^\s*//.*$#m', '', $config);

    expect($uncommented)->toContain('starlight(')
        ->and($uncommented)->not->toMatch('/^\s*base\s*:/m')
        ->and($uncommented)->not->toMatch('/^\s*trailingSlash\s*:/m')
        ->and($uncommented)->not->toContain('format:');
});
