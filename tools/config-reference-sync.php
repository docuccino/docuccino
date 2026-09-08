<?php

declare(strict_types=1);
use Docuccino\Laravel\Config\DeclaredSettings;

// Configuration-reference sync guard.
//
// Two files are the shipped surface — `docuccino.yaml` for everything that shapes a document, and
// `php/laravel/config/docuccino.php` for what Laravel reads while it boots — each with every option
// present and the optional ones commented out. The website's configuration reference is supposed to
// document all of it. That rule lived in CONTRIBUTING.md and nowhere else, so it drifted: keys landed
// in a config file while the page said nothing about them, and the page described shapes neither file
// mentioned. This reads all three sides and reports the difference.
//
// The two files are ONE surface here, unioned rather than compared against each other: they share a
// key namespace and they partition it, so `cache.enabled` and `cache.store` are two keys of one
// `cache` family and the page documents each wherever it explains it. Which file a key came from is
// carried only so the report can name it.
//
// Every side is read the same way. A commented-out key is still a key, so each config reader
// un-comments the lines that are config rather than prose — and the page's `php` and `yaml` blocks,
// the mirror most likely to fall behind, go through those same two readers, picked by the fence's
// language. Tables contribute the key in each row's first column, but only where that column is
// headed `Key`: the page's other tables list integration bags, tag-object fields and credential
// shapes, none of which are config keys.
//
// A `yaml` block on the page therefore has to parse, and an unparseable one raises out of here rather
// than reading as no keys at all: a block quietly contributing nothing comes back as every key under
// it being undocumented, which names the symptom and not the cause.
//
// Nothing is added to the page to make this work. Which part of the config a section covers is
// stated here instead, in SECTIONS, because the page's own headings can't say it — several of the
// lint sections are named for what they warn about rather than for their key.
//
// Requiring this file has no side effects, so tests can point it at synthetic sources.

/** The build configuration, at the project root — the file this repository ships as its own default. */
const CONFIG_REFERENCE_SETTINGS = 'docuccino.yaml';

/** The framework configuration, published into `config/` — what boot and a viewer request read. */
const CONFIG_REFERENCE_FRAMEWORK = 'config/docuccino.php';

/**
 * Heading line => the config path that section documents. Exhaustive for the sections carrying a
 * `php`/`yaml` example or a `Key` table; the guard fails on one that carries either and isn't here,
 * so a new section cannot be documented into a hole.
 *
 * Two headings map to the root, one per file: the page is in two parts, and each part opens by
 * showing its own file's top level.
 */
const CONFIG_REFERENCE_SECTIONS = [
    // The build half: docuccino.yaml.
    '## Build configuration' => '',
    '### `api_version`' => 'documents.*.api_version',
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
    // The boot half: config/docuccino.php.
    '## Boot configuration' => '',
    '### `viewer`' => 'documents.*.viewer',
];

/**
 * Map keys whose names the application chooses. The segment below one of these is a name, not a key,
 * so it normalizes to `*` on both sides. Off {@see DeclaredSettings}, because the PRODUCT reads the
 * same surface — `config.unknown-setting` reports a key the shipped file does not declare — and two
 * spellings of this rule would let this guard and that report disagree about what a key even is.
 */
const CONFIG_REFERENCE_KEYED_MAPS = DeclaredSettings::KEYED_MAPS;

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
 * The key paths the tool's own `docuccino.yaml` declares, live and commented-out alike, dotted and
 * sorted — the same answer {@see config_reference_declared_keys()} gives for the framework's PHP
 * config, so the page can be held to both files with one comparison.
 *
 * The reading itself is {@see DeclaredSettings::of()}, in the package, for the reason above the keyed
 * maps: the product derives its own settings surface from these bytes, and a second implementation
 * of "what does the shipped file declare" would let the two answer differently. Every test below
 * naming this function is that reader's test.
 *
 * @return list<string>
 *
 * @internal
 */
function config_reference_yaml_keys(string $yaml, string $base = ''): array
{
    return DeclaredSettings::of($yaml, $base);
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

        $paths = array_merge($paths, config_reference_section_block_keys($body, $prefix));

        foreach (config_reference_table_keys($body) as $key) {
            $paths[] = config_reference_join($prefix, $key);
        }
    }

    return config_reference_normalize($paths);
}

/**
 * Every disagreement between the two shipped config files and the reference page, as lines a
 * developer can act on. Empty means the three are in sync.
 *
 * `undocumented` names the file the key ships in, because that is where the reader has to go to see
 * it; `invented` names neither, because the whole point of that line is that no file holds the key.
 *
 * @param  array<string, string>|null  $sections  heading => prefix, defaulting to the page's own map
 * @return list<string>
 *
 * @internal
 */
function config_reference_problems(string $php, string $yaml, string $markdown, ?array $sections = null): array
{
    $sections ??= CONFIG_REFERENCE_SECTIONS;
    $shipped = config_reference_shipped($php, $yaml);
    $declared = array_keys($shipped);
    $documented = config_reference_checkable(config_reference_documented_keys($markdown, $sections));

    $problems = [];

    foreach (array_diff($declared, $documented) as $key) {
        $problems[] = 'undocumented:  '.$key.'  (in '.$shipped[$key].', missing from the reference)';
    }

    foreach (array_diff($documented, $declared) as $key) {
        $problems[] = 'invented:      '.$key.'  (documented, but in neither shipped configuration file)';
    }

    $present = config_reference_sections($markdown);

    foreach ($present as $heading => $body) {
        if (array_key_exists($heading, $sections)) {
            continue;
        }

        if (config_reference_section_block_keys($body, '') !== [] || config_reference_table_keys($body) !== []) {
            $problems[] = 'unmapped:      '.$heading.'  (documents keys; map it in tools/config-reference-sync.php)';
        }
    }

    foreach ($sections as $heading => $prefix) {
        $key = rtrim(preg_replace('/(\.\*)+$/', '', $prefix) ?? '', '.');

        if (! array_key_exists($heading, $present)) {
            $problems[] = 'missing:       '.$heading.'  (mapped, but the reference has no such section)';
        }

        if ($key !== '' && ! in_array($key, $declared, true)) {
            $problems[] = 'stale mapping: '.$heading.' => '.$prefix.'  (no such key in either shipped configuration file)';
        }
    }

    sort($problems);

    return $problems;
}

/**
 * The whole shipped surface as key => the file it is declared in, checkable paths only.
 *
 * A key both files declare — `documents` and `cache` are the two bags that straddle the split — is
 * attributed to the build file, which is the one an author edits. Nothing rests on the attribution
 * beyond the wording of a report.
 *
 * @return array<string, string>
 *
 * @internal
 */
function config_reference_shipped(string $php, string $yaml): array
{
    $shipped = [];

    foreach (config_reference_checkable(config_reference_declared_keys($php)) as $key) {
        $shipped[$key] = CONFIG_REFERENCE_FRAMEWORK;
    }

    foreach (config_reference_checkable(config_reference_yaml_keys($yaml)) as $key) {
        $shipped[$key] = CONFIG_REFERENCE_SETTINGS;
    }

    ksort($shipped, SORT_STRING);

    return $shipped;
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
    return config_reference_fenced($body, 'php');
}

/**
 * The `yaml` fenced blocks in a stretch of markdown.
 *
 * @return list<string>
 *
 * @internal
 */
function config_reference_yaml_blocks(string $body): array
{
    return config_reference_fenced($body, 'yaml');
}

/**
 * The fenced blocks in one language. The language has to be exact, so a ```` ```yaml title="…" ````
 * fence is not read at all — which fails loudly rather than quietly: every key that block was the only
 * home for comes back `undocumented`, naming the keys and pointing at the section they live in.
 *
 * @return list<string>
 *
 * @internal
 */
function config_reference_fenced(string $body, string $language): array
{
    $blocks = [];
    $current = null;

    foreach (explode("\n", $body) as $line) {
        if ($current === null) {
            if (trim($line) === '```'.$language) {
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
 * Every key a section's fenced blocks document, in either spelling, read from that section's path.
 *
 * @return list<string>
 *
 * @internal
 */
function config_reference_section_block_keys(string $body, string $prefix): array
{
    $paths = [];

    foreach (config_reference_php_blocks($body) as $block) {
        $paths = array_merge($paths, config_reference_block_keys($block, $prefix, config_reference_declared_keys(...)));
    }

    foreach (config_reference_yaml_blocks($body) as $block) {
        $paths = array_merge($paths, config_reference_block_keys($block, $prefix, config_reference_yaml_keys(...)));
    }

    return $paths;
}

/**
 * A block quotes its keys in context — `viewer:` with its members under it in the viewer section — so
 * a block that opens with the section's own key is read from the section's parent, and one that
 * doesn't (a bare `middleware:` in an aside) is read from the section itself.
 *
 * The reader is passed in rather than chosen here, because which one applies is a property of the
 * fence and this rule is a property of the page.
 *
 * @param  Closure(string, string): list<string>  $reader
 * @return list<string>
 *
 * @internal
 */
function config_reference_block_keys(string $block, string $prefix, Closure $reader): array
{
    $keys = $reader($block, '');

    if ($keys === [] || $prefix === '') {
        return $reader($block, $prefix);
    }

    $segments = explode('.', $prefix);
    $own = (string) array_pop($segments);
    $quoted = true;

    foreach ($keys as $key) {
        $quoted = $quoted && ($key === $own || str_starts_with($key, $own.'.'));
    }

    return $reader($block, $quoted ? implode('.', $segments) : $prefix);
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
 * are the one key they describe. {@see DeclaredSettings::normalized()} owns the rule, so the PHP
 * reader here and the product's own reader cannot part company over it.
 *
 * @param  list<string>  $paths
 * @return list<string>
 *
 * @internal
 */
function config_reference_normalize(array $paths): array
{
    return DeclaredSettings::normalized($paths);
}
