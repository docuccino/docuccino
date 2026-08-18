<?php

declare(strict_types=1);

// Side-effect free, and where this repo's scripts keep `conventional_is_entry_script()`.
require_once __DIR__.'/conventional-commit.php';

/*
 * Generator-version writer.
 *
 * `DocuccinoServiceProvider::VERSION` is the one string in this repository that names the release
 * being cut — every emitted document publishes it, and it keys the fragment cache's tool version.
 * release-pr.yml runs this alongside the changelog generator, so the constant rides the same
 * `release/next` commit and no maintainer ever types it (RELEASING.md).
 *
 * Idempotent: a re-run, or the state between merging a release and pushing its tag, finds the
 * constant already at the target and writes nothing. That is what keeps the workflow's
 * `git status --porcelain` check honest about whether a release is pending.
 *
 * Loud rather than silent: the declaration must match exactly once. A refactor that renames it,
 * reformats it or grows a second copy fails the release run rather than shipping a document that
 * names a generator that never produced it.
 *
 * Requiring this file has no side effects, so tests can point it at synthetic trees.
 *
 * Usage:
 *   php tools/generator-version.php v1.2.3
 */

const GENERATOR_VERSION_PATH = 'php/laravel/src/DocuccinoServiceProvider.php';

/** The declaration, captured either side of the literal so only the version itself is rewritten. */
const GENERATOR_VERSION_PATTERN = "/(const string VERSION = ')(\d+\.\d+\.\d+)(';)/";

/**
 * A release version as the constant spells it — no leading `v` — or null when the argument is not
 * one. `tools/changelog.php --print-version` prints `v1.2.3`; the constant holds `1.2.3`.
 */
function generator_version_normalise(string $version): ?string
{
    $bare = ltrim(trim($version), 'v');

    return preg_match('/^\d+\.\d+\.\d+$/', $bare) === 1 ? $bare : null;
}

/**
 * The source with its VERSION constant set to $version, or null when the declaration is not there
 * exactly once — the caller reports that rather than writing a file it did not understand.
 */
function generator_version_rewrite(string $source, string $version): ?string
{
    if (preg_match_all(GENERATOR_VERSION_PATTERN, $source) !== 1) {
        return null;
    }

    return (string) preg_replace(GENERATOR_VERSION_PATTERN, '${1}'.$version.'${3}', $source);
}

/**
 * @param  resource  $stdout
 * @param  resource  $stderr
 *
 * @internal
 */
function generator_version_main(string $version, string $repoRoot, $stdout = STDOUT, $stderr = STDERR): int
{
    $bare = generator_version_normalise($version);
    if ($bare === null) {
        fwrite($stderr, sprintf(
            "generator-version: `%s` is not a release version — pass `v1.2.3` or `1.2.3`.\n",
            trim($version),
        ));

        return 1;
    }

    $path = $repoRoot.'/'.GENERATOR_VERSION_PATH;
    $source = is_file($path) ? (string) file_get_contents($path) : null;

    if ($source === null) {
        fwrite($stderr, sprintf("generator-version: %s is not there.\n", GENERATOR_VERSION_PATH));

        return 1;
    }

    $rewritten = generator_version_rewrite($source, $bare);
    if ($rewritten === null) {
        fwrite($stderr, sprintf(
            "generator-version: expected exactly one `const string VERSION = '1.2.3';` in %s, found %d.\n".
            "The release workflow bumps that constant, so a renamed or duplicated declaration would ship a stale\n".
            "generator version in every document. Restore it, or teach tools/generator-version.php the new shape.\n",
            GENERATOR_VERSION_PATH,
            preg_match_all(GENERATOR_VERSION_PATTERN, $source),
        ));

        return 1;
    }

    if ($rewritten === $source) {
        fwrite($stdout, sprintf("generator-version: unchanged, already %s\n", $bare));

        return 0;
    }

    file_put_contents($path, $rewritten);
    fwrite($stdout, sprintf("generator-version: %s written to %s\n", $bare, GENERATOR_VERSION_PATH));

    return 0;
}

if (conventional_is_entry_script(__FILE__)) {
    $argument = $_SERVER['argv'][1] ?? '';
    exit(generator_version_main(is_string($argument) ? $argument : '', dirname(__DIR__)));
}
