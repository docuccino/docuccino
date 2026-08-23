<?php

declare(strict_types=1);

// Configuration-reference sync guard.
//
// `php/laravel/config/docuccino.php` is the shipped surface — every option present, the optional
// ones commented out — and the website's configuration reference is supposed to document all of it.
// That rule lived in CONTRIBUTING.md and nowhere else, so it drifted: keys landed in the config file
// while the page said nothing about them, and the page described shapes the file never mentioned.
// This reads both sides and reports the difference.
//
// Both sides are read the same way. A commented-out key is still a key, so the config reader
// un-comments the lines that are config rather than prose — and the page's `php` blocks, the mirror
// most likely to fall behind, go through that same reader. Tables contribute the key in each row's
// first column, but only where that column is headed `Key`: the page's other tables list integration
// bags, tag-object fields and credential shapes, none of which are config keys.
//
// Nothing is added to the page to make this work. Which part of the config a section covers is
// stated here instead, in SECTIONS, because the page's own headings can't say it — several of the
// lint sections are named for what they warn about rather than for their key.
//
// Requiring this file has no side effects, so tests can point it at synthetic sources.

/**
 * Heading line => the config path that section documents. Exhaustive for the sections carrying a
 * `php` example or a `Key` table; the guard fails on one that carries either and isn't here, so a
 * new section cannot be documented into a hole.
 */
const CONFIG_REFERENCE_SECTIONS = [
    '## Top level' => '',
    '### `info`' => 'documents.*.info',
    '### `servers`' => 'documents.*.servers',
    '### `routes`' => 'documents.*.routes',
    '### `security`' => 'documents.*.security',
    '### `error_responses`' => 'documents.*.error_responses',
    '### `tags`' => 'documents.*.tags',
    '### `webhooks`' => 'documents.*.webhooks',
    '### `content`' => 'documents.*.content',
    '### `examples`' => 'documents.*.examples',
    '### `coverage`' => 'documents.*.coverage',
    '### `overlays`' => 'documents.*.overlays',
    '### `representation`' => 'documents.*.representation',
    '### `integrations`' => 'documents.*.integrations',
    '### `export`' => 'documents.*.export',
    '### `viewer`' => 'documents.*.viewer',
    '### `versioning`' => 'documents.*.versioning',
    '## Extensions' => 'extensions',
    '## Lint' => 'lint',
    '### Data leakage' => 'lint.leakage',
    '### Descriptions' => 'lint.descriptions',
    '### Operation ids' => 'lint.operation_ids',
    '### Undocumented tags' => 'lint.tags',
    '### Vacuous union' => 'lint.vacuous_union',
    '## Diagnostics' => 'diagnostics',
    '## Engine' => 'engine',
    '## Cache' => 'cache',
];

/**
 * Map keys whose names the application chooses. The segment below one of these is a name, not a key,
 * so it normalizes to `*` on both sides.
 */
const CONFIG_REFERENCE_KEYED_MAPS = [
    'documents',
];

/**
 * Subtrees whose contents are the reader's own data or verbatim OpenAPI, never Docuccino keys. The
 * listed path stays checked; nothing below it is. Each one has to be here for a reason a reader
 * would recognize — a broad entry would hide exactly the drift this guard exists to catch.
 */
const CONFIG_REFERENCE_OPAQUE = [
    // OAS Server Objects, emitted as written (url, description, variables, and whatever else you add).
    'documents.*.servers',
    // Your scheme names, each holding an OAS Security Scheme Object.
    'documents.*.security.schemes',
    // OAS Security Requirement lists, keyed by the scheme names above.
    'documents.*.security.default',
    'documents.*.security.document',
    // Raw tag => display tag, both halves yours.
    'documents.*.tags.map',
    // Token => label heuristics, both halves yours.
    'lint.leakage.patterns',
    // Filter kind => your own sentence. The kinds are a closed set Docuccino owns, so a typo there is
    // caught by the `config.unknown-filter-kind` diagnostic rather than by this guard.
    'documents.*.integrations.query_builder.filter_descriptions',
    // JSON Schema `format` => your own sample. The formats are the spec's, not ours, and a format no
    // schema uses is not an error — examples are demand-driven.
    'documents.*.representation.examples.formats',
];

/**
 * Keys the page documents once for a whole family rather than once each. Only where repeating it per
 * member would be noise, and where the members themselves are still checked.
 */
const CONFIG_REFERENCE_DOCUMENTED_ONCE = [
    // Every integration bag takes `enabled`; the page says so once and tables the default per bag.
    'documents.*.integrations.*.enabled',
];

/**
 * The key paths a config file declares, live and commented-out alike, dotted and sorted.
 *
 * Accepts a whole config file or a fragment of one (a `php` block from the docs), which is why the
 * `return [` root is recognized by the `return` rather than by being the first bracket.
 *
 * @return list<string>
 *
 * @internal
 */
function config_reference_declared_keys(string $php, string $base = ''): array
{
    $tokens = PhpToken::tokenize(config_reference_uncomment($php));

    $stack = [];
    $paths = [];
    $pending = null;
    $root = false;

    foreach ($tokens as $index => $token) {
        if ($token->isIgnorable() || $token->is(T_DOUBLE_ARROW)) {
            continue;
        }

        if ($token->is(T_RETURN)) {
            $root = true;

            continue;
        }

        if ($token->text === '[') {
            $stack[] = $root ? null : ($pending ?? '*');
            $root = false;
            $pending = null;

            continue;
        }

        if ($token->text === ']') {
            array_pop($stack);
            $pending = null;

            continue;
        }

        $pending = null;

        if (! $token->is(T_CONSTANT_ENCAPSED_STRING)) {
            continue;
        }

        $next = $index + 1;
        while (isset($tokens[$next]) && $tokens[$next]->isIgnorable()) {
            $next++;
        }

        if (! isset($tokens[$next]) || ! $tokens[$next]->is(T_DOUBLE_ARROW)) {
            continue;
        }

        $key = substr($token->text, 1, -1);

        // A key an application would write. Anything else — a JSON pointer, a credential shape in an
        // example — is a value that happens to sit left of an arrow inside somebody's data.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $key) !== 1) {
            continue;
        }

        $segments = array_values(array_filter($stack, static fn (?string $segment): bool => $segment !== null));
        $segments[] = $key;
        $paths[] = config_reference_join($base, implode('.', $segments));
        $pending = $key;
    }

    return config_reference_normalize($paths);
}

/**
 * The key paths the configuration reference documents, dotted and sorted.
 *
 * @param  array<string, string>|null  $sections  heading => prefix, defaulting to the page's own map
 * @return list<string>
 *
 * @internal
 */
function config_reference_documented_keys(string $markdown, ?array $sections = null): array
{
    $sections ??= CONFIG_REFERENCE_SECTIONS;
    $paths = [];

    foreach (config_reference_sections($markdown) as $heading => $body) {
        if (! array_key_exists($heading, $sections)) {
            continue;
        }

        $prefix = $sections[$heading];

        foreach (config_reference_php_blocks($body) as $block) {
            $paths = array_merge($paths, config_reference_block_keys($block, $prefix));
        }

        foreach (config_reference_table_keys($body) as $key) {
            $paths[] = config_reference_join($prefix, $key);
        }
    }

    return config_reference_normalize($paths);
}

/**
 * Every disagreement between the shipped config and the reference page, as lines a developer can act
 * on. Empty means the two are in sync.
 *
 * @param  array<string, string>|null  $sections  heading => prefix, defaulting to the page's own map
 * @return list<string>
 *
 * @internal
 */
function config_reference_problems(string $php, string $markdown, ?array $sections = null): array
{
    $sections ??= CONFIG_REFERENCE_SECTIONS;
    $declared = config_reference_checkable(config_reference_declared_keys($php));
    $documented = config_reference_checkable(config_reference_documented_keys($markdown, $sections));

    $problems = [];

    foreach (array_diff($declared, $documented) as $key) {
        $problems[] = 'undocumented:  '.$key.'  (in config/docuccino.php, missing from the reference)';
    }

    foreach (array_diff($documented, $declared) as $key) {
        $problems[] = 'invented:      '.$key.'  (documented, but no such key in config/docuccino.php)';
    }

    $present = config_reference_sections($markdown);

    foreach ($present as $heading => $body) {
        if (array_key_exists($heading, $sections)) {
            continue;
        }

        if (config_reference_php_blocks($body) !== [] || config_reference_table_keys($body) !== []) {
            $problems[] = 'unmapped:      '.$heading.'  (documents keys; map it in tools/config-reference-sync.php)';
        }
    }

    foreach ($sections as $heading => $prefix) {
        $key = rtrim(preg_replace('/(\.\*)+$/', '', $prefix) ?? '', '.');

        if (! array_key_exists($heading, $present)) {
            $problems[] = 'missing:       '.$heading.'  (mapped, but the reference has no such section)';
        }

        if ($key !== '' && ! in_array($key, $declared, true)) {
            $problems[] = 'stale mapping: '.$heading.' => '.$prefix.'  (no such key in config/docuccino.php)';
        }
    }

    sort($problems);

    return $problems;
}

/**
 * Drops the paths nobody can look up: a wildcard segment names no key, and the two exception lists
 * above name the rest.
 *
 * @param  list<string>  $paths
 * @return list<string>
 *
 * @internal
 */
function config_reference_checkable(array $paths): array
{
    $checkable = [];

    foreach ($paths as $path) {
        if (str_ends_with($path, '*')) {
            continue;
        }

        foreach (CONFIG_REFERENCE_DOCUMENTED_ONCE as $family) {
            if (preg_match('/^'.str_replace('\*', '[^.]+', preg_quote($family, '/')).'$/', $path) === 1) {
                continue 2;
            }
        }

        foreach (CONFIG_REFERENCE_OPAQUE as $opaque) {
            if (str_starts_with($path, $opaque.'.')) {
                continue 2;
            }
        }

        $checkable[] = $path;
    }

    return $checkable;
}

/**
 * The config file with its prose stripped and its commented-out keys made live, so one reader covers
 * both halves of the shipped surface.
 *
 * @internal
 */
function config_reference_uncomment(string $php): string
{
    $kept = [];
    $banner = false;

    foreach (explode("\n", $php) as $line) {
        $trimmed = trim($line);

        if ($banner) {
            $banner = ! str_contains($trimmed, '*/');

            continue;
        }

        if (str_starts_with($trimmed, '/*')) {
            $banner = ! str_contains($trimmed, '*/');

            continue;
        }

        if (! str_starts_with($trimmed, '//')) {
            $kept[] = $line;

            continue;
        }

        $content = ltrim(substr($trimmed, 2));

        // A commented-out key, or a bracket opening or closing one. Prose wraps onto lines that start
        // the same way, so a line opening with a bracket only counts when it closes on config too.
        if (preg_match("/^'[^']*'\s*=>/", $content) === 1
            || preg_match('/^\],?$/', $content) === 1
            || preg_match('/^\[.*(\[|\],?)$/', $content) === 1) {
            $kept[] = $content;
        }
    }

    $source = implode("\n", $kept);

    return str_starts_with(ltrim($source), '<?php') ? $source : "<?php\n".$source;
}

/**
 * The page split by heading, as heading line => everything under it up to the next heading. A `#`
 * inside a fenced block is code, not a heading.
 *
 * @return array<string, string>
 *
 * @internal
 */
function config_reference_sections(string $markdown): array
{
    $sections = [];
    $heading = null;
    $body = [];
    $fenced = false;

    foreach (explode("\n", $markdown) as $line) {
        if (str_starts_with(trim($line), '```')) {
            $fenced = ! $fenced;
        }

        if (! $fenced && preg_match('/^#{1,6}\s/', $line) === 1) {
            if ($heading !== null) {
                $sections[$heading] = implode("\n", $body);
            }

            $heading = rtrim($line);
            $body = [];

            continue;
        }

        $body[] = $line;
    }

    if ($heading !== null) {
        $sections[$heading] = implode("\n", $body);
    }

    return $sections;
}

/**
 * The `php` fenced blocks in a stretch of markdown.
 *
 * @return list<string>
 *
 * @internal
 */
function config_reference_php_blocks(string $body): array
{
    $blocks = [];
    $current = null;

    foreach (explode("\n", $body) as $line) {
        if ($current === null) {
            if (trim($line) === '```php') {
                $current = [];
            }

            continue;
        }

        if (trim($line) === '```') {
            $blocks[] = implode("\n", $current);
            $current = null;

            continue;
        }

        $current[] = $line;
    }

    return $blocks;
}

/**
 * A block quotes its keys in context — `'viewer' => [...]` in the viewer section — so a block that
 * opens with the section's own key is read from the section's parent, and one that doesn't
 * (`'middleware' => [...]` in an aside) is read from the section itself.
 *
 * @return list<string>
 *
 * @internal
 */
function config_reference_block_keys(string $block, string $prefix): array
{
    $keys = config_reference_declared_keys($block);

    if ($keys === [] || $prefix === '') {
        return config_reference_declared_keys($block, $prefix);
    }

    $segments = explode('.', $prefix);
    $own = (string) array_pop($segments);
    $quoted = true;

    foreach ($keys as $key) {
        $quoted = $quoted && ($key === $own || str_starts_with($key, $own.'.'));
    }

    return config_reference_declared_keys($block, $quoted ? implode('.', $segments) : $prefix);
}

/**
 * The key in each row of every table whose first column is headed `Key`. The page's other tables
 * list integration bags, tag-object fields and credential shapes — none of them config keys — and a
 * first cell that isn't a lone code span is a label rather than a key.
 *
 * @return list<string>
 *
 * @internal
 */
function config_reference_table_keys(string $body): array
{
    $keys = [];
    $reading = false;

    foreach (explode("\n", $body) as $line) {
        $trimmed = trim($line);

        if (! str_starts_with($trimmed, '|')) {
            $reading = false;

            continue;
        }

        $cell = trim(explode('|', $trimmed)[1] ?? '');

        if (! $reading) {
            $reading = $cell === 'Key';

            continue;
        }

        if (preg_match('/^`([A-Za-z_][A-Za-z0-9_.-]*)`$/', $cell, $matches) === 1) {
            $keys[] = $matches[1];
        }
    }

    return $keys;
}

/**
 * @internal
 */
function config_reference_join(string $prefix, string $path): string
{
    return $prefix === '' ? $path : $prefix.'.'.$path;
}

/**
 * Application-chosen names collapse to `*`, so `documents.default.viewer` and `documents.*.viewer`
 * are the one key they describe.
 *
 * @param  list<string>  $paths
 * @return list<string>
 *
 * @internal
 */
function config_reference_normalize(array $paths): array
{
    $normalized = [];

    foreach ($paths as $path) {
        $segments = explode('.', $path);

        foreach (array_keys($segments) as $index) {
            if ($index > 0 && in_array($segments[$index - 1], CONFIG_REFERENCE_KEYED_MAPS, true)) {
                $segments[$index] = '*';
            }
        }

        $normalized[] = implode('.', $segments);
    }

    $normalized = array_values(array_unique($normalized));
    sort($normalized);

    return $normalized;
}
