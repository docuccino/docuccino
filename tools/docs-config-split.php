<?php

declare(strict_types=1);
use Docuccino\Core\Config\ConfigFile;

require_once __DIR__.'/config-reference-sync.php';

// Documentation-site config-split guard.
//
// Configuration lives in two files, and which one a setting belongs to decides whether editing it does
// anything: `docuccino.yaml` is read once per build and holds everything that shapes a document, while
// `config/docuccino.php` holds only what Laravel reads while it boots. So a page showing a build
// setting as a PHP array is not a formatting slip — a reader who follows it gets a setting nothing
// reads plus a `config.stale-php-keys` warning.
//
// `ConfigReferenceSyncTest` holds the reference page to the shipped files, key for key, and only that
// page. This covers the rest of the site, and it reads KEYS rather than the path comment a block may
// or may not open with: a snippet that names no file is exactly the one a reader cannot check.
//
// Two questions, in order. **Is this block configuration at all?** A `php` fence on the docs site is
// usually the reader's own code, and an array literal in it — a `rules()` return, a JSON Schema —
// carries keys that are nobody's settings. So a block counts only when one of its keys NAMES a
// setting, at one of the three levels a snippet is realistically quoted from: the file's top level, a
// document, or a viewer. Every name comes off the shipped files.
//
// **Then: is every key in it a boot key?** The boot surface is whatever `config/docuccino.php` ships,
// read the same way. A snippet is quoted from wherever it sits, so `'source' => 'artifact'` arrives as
// the bare key `source` and is admitted as the tail of `documents.*.viewer.source`; `error_responses`
// is the tail of nothing the framework file holds, and is reported.
//
// Only the PHP direction is guarded. The mirror — a boot setting written as YAML — is the drift that
// has never happened, and catching it would mean parsing every `yaml` block on the site, including the
// overlays, workflows and spec excerpts that are not configuration and do not all parse out of the
// indentation a `<Steps>` list gives them. A guard sized to a hypothesis, over a corpus it has to
// mangle to read, is not worth what it would cost to keep.
//
// Requiring this file has no side effects, so tests can point it at synthetic pages.

/** Where the pages live, relative to the repository root. */
const DOCS_CONFIG_SPLIT_ROOT = 'website/src/content/docs';

/**
 * The names a settings snippet can open with, off both shipped files.
 *
 * Three levels, because those are the three a page quotes from: the file's own top level
 * (`extensions`, `lint`), one document (`routes`, `viewer`), and one viewer (`gate`, `source`).
 * Deeper leaves are deliberately left out — `path`, `mode`, `default` and `title` are words an
 * application's own arrays are full of, and a name that common would make this a scan of every
 * fenced block on the site.
 *
 * @param  list<string>  $paths  dotted key paths, as the two readers return them
 * @return list<string>
 *
 * @internal
 */
function docs_config_setting_names(array $paths): array
{
    $names = [];

    foreach ($paths as $path) {
        $segments = explode('.', $path);

        $name = match (true) {
            count($segments) === 1 => $segments[0],
            count($segments) === 3 && $segments[0] === 'documents' => $segments[2],
            count($segments) === 4 && $segments[0] === 'documents' && $segments[2] === 'viewer' => $segments[3],
            default => null,
        };

        if ($name !== null && $name !== '*') {
            $names[$name] = true;
        }
    }

    $names = array_keys($names);
    sort($names);

    return $names;
}

/**
 * Whether one dotted path is the tail of another.
 *
 * A page quotes a snippet from wherever it sits, so the path it declares is a suffix of the path the
 * shipped file declares — `viewer.gate` and `gate` are both the framework's `documents.*.viewer.gate`.
 *
 * The LAST segment has to match literally, and only the segments above it treat `*` as a wildcard.
 * That asymmetry is the whole check: a wildcard tail would admit any one-segment key at all, because
 * the shipped `documents.*` ends in the placeholder a document's name fills, and `error_responses`
 * sits exactly where that name does.
 *
 * @internal
 */
function docs_config_is_tail_of(string $path, string $of): bool
{
    $needle = explode('.', $path);
    $hay = explode('.', $of);

    if (count($needle) > count($hay)) {
        return false;
    }

    $tail = array_slice($hay, count($hay) - count($needle));

    if (end($needle) !== end($tail)) {
        return false;
    }

    foreach (array_slice($needle, 0, -1) as $index => $segment) {
        if ($segment !== $tail[$index] && $segment !== '*' && $tail[$index] !== '*') {
            return false;
        }
    }

    return true;
}

/**
 * Every configuration `php` block a page shows, as the keys it declares.
 *
 * @param  list<string>  $names  what {@see docs_config_setting_names()} returned
 * @return list<list<string>>
 *
 * @internal
 */
function docs_config_php_blocks(string $markdown, array $names): array
{
    $blocks = [];

    foreach (config_reference_php_blocks($markdown) as $block) {
        $keys = config_reference_declared_keys($block);

        if (array_intersect($keys, $names) !== []) {
            $blocks[] = $keys;
        }
    }

    return $blocks;
}

/**
 * Every build setting the site shows as PHP, as lines a developer can act on. Empty means every `php`
 * block that is configuration shows only what the framework config still holds.
 *
 * @param  array<string, string>  $pages  path => contents
 * @return list<string>
 *
 * @internal
 */
function docs_config_split_problems(array $pages, string $php, string $yaml): array
{
    $boot = config_reference_declared_keys($php);
    $names = docs_config_setting_names(array_merge($boot, config_reference_yaml_keys($yaml)));

    $problems = [];

    foreach ($pages as $page => $markdown) {
        foreach (docs_config_php_blocks($markdown, $names) as $keys) {
            foreach ($keys as $key) {
                $admitted = false;

                foreach ($boot as $path) {
                    if (docs_config_is_tail_of($key, $path)) {
                        $admitted = true;

                        break;
                    }
                }

                if (! $admitted) {
                    $problems[] = 'build setting as PHP:  '.$key.'  ('.$page.' — read from '.ConfigFile::NAME.', so show it as YAML)';
                }
            }
        }
    }

    sort($problems);

    return array_values(array_unique($problems));
}

/**
 * How many configuration `php` blocks the site shows, and how many pages carry one — the counts a
 * scanner that stopped recognizing its shapes would drop to zero while reporting nothing wrong.
 *
 * @param  array<string, string>  $pages  path => contents
 * @return array{blocks: int, pages: int}
 *
 * @internal
 */
function docs_config_split_reach(array $pages, string $php, string $yaml): array
{
    $boot = config_reference_declared_keys($php);
    $names = docs_config_setting_names(array_merge($boot, config_reference_yaml_keys($yaml)));

    $blocks = 0;
    $carrying = 0;

    foreach ($pages as $markdown) {
        $found = count(docs_config_php_blocks($markdown, $names));
        $blocks += $found;
        $carrying += $found > 0 ? 1 : 0;
    }

    return ['blocks' => $blocks, 'pages' => $carrying];
}

/**
 * Every documentation page, as path relative to the repository root => contents.
 *
 * @return array<string, string>
 *
 * @internal
 */
function docs_config_split_pages(string $root): array
{
    $pages = [];

    /** @var iterable<string, SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['md', 'mdx'], true)) {
            $pages[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($pages, SORT_STRING);

    return $pages;
}
