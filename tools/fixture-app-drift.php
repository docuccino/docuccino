<?php

declare(strict_types=1);

// Side-effect free, and where this repo's scripts keep `conventional_is_entry_script()`.
require_once __DIR__.'/conventional-commit.php';

/*
 * Fixture-app drift guard.
 *
 * `tests/fixture-app/app/` is git-ignored and provisioned by copying the tracked overlay in
 * `tests/fixture-app/src/` over a Laravel install (steps 5-6 of tests/fixture-app/setup.md). Nothing
 * re-copies it afterwards, so editing a tracked source leaves the provisioned copy behind — and the
 * fixture group then fails against the OLD code, which reads exactly like the developer's own change
 * having broken something. This compares the two and says which it is, before a single engine
 * subprocess starts.
 *
 * Inert without a provisioned app: a contributor who never provisioned one gets the same green run the
 * fixture group's own skip gives them.
 *
 * Requiring this file has no side effects, so tests can point it at synthetic trees.
 *
 * Usage:
 *   php tools/fixture-app-drift.php
 */

/**
 * Every file under $root, as paths relative to it, sorted — so a report reads the same on every host.
 *
 * @return list<string>
 *
 * @internal
 */
function fixture_drift_files(string $root): array
{
    if (! is_dir($root)) {
        return [];
    }

    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $found[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    sort($found);

    return $found;
}

/**
 * What the provisioned app owes the tracked overlay: one line per source that is missing from it or
 * differs from it. Empty means the two agree.
 *
 * The tracked set is read off the filesystem rather than out of git: `tests/fixture-app/src/` carries
 * nothing but the overlay, and a guard that needs a git checkout would be inert in the one place it
 * is cheapest to run.
 *
 * @return list<string>
 *
 * @internal
 */
function fixture_drift_problems(string $trackedRoot, string $appRoot): array
{
    $problems = [];

    // src/app/… overlays app/app/…, src/modules/… overlays app/modules/… — the two copies setup.md makes.
    foreach (['app', 'modules'] as $overlay) {
        $source = $trackedRoot.'/'.$overlay;

        foreach (fixture_drift_files($source) as $relative) {
            $tracked = $source.'/'.$relative;
            $provisioned = $appRoot.'/'.$overlay.'/'.$relative;

            if (! is_file($provisioned)) {
                $problems[] = sprintf('missing:  %s/%s', $overlay, $relative);

                continue;
            }

            if (@file_get_contents($tracked) !== @file_get_contents($provisioned)) {
                $problems[] = sprintf('differs:  %s/%s', $overlay, $relative);
            }
        }
    }

    return $problems;
}

/**
 * @param  resource  $stderr
 *
 * @internal
 */
function fixture_drift_main(string $repoRoot, $stderr = STDERR): int
{
    $appRoot = $repoRoot.'/tests/fixture-app/app';

    // The same signal the fixture group gates on: no install, no fixture tests, nothing to drift.
    if (! is_file($appRoot.'/vendor/autoload.php')) {
        return 0;
    }

    $problems = fixture_drift_problems($repoRoot.'/tests/fixture-app/src', $appRoot);
    if ($problems === []) {
        return 0;
    }

    fwrite($stderr, "The provisioned fixture app has drifted from the tracked sources:\n\n");
    foreach ($problems as $problem) {
        fwrite($stderr, '  '.$problem."\n");
    }
    fwrite($stderr, <<<'TEXT'

        tests/fixture-app/app/ is git-ignored and only ever written by provisioning, so the fixture
        group is analysing the code above as it was, not as it is now. Re-apply steps 5-6 of
        tests/fixture-app/setup.md from the repository root:

          cp -R tests/fixture-app/src/app/. tests/fixture-app/app/app/
          cp -R tests/fixture-app/src/modules/. tests/fixture-app/app/modules/
          composer dump-autoload --working-dir=tests/fixture-app/app

        TEXT);

    return 1;
}

if (conventional_is_entry_script(__FILE__)) {
    exit(fixture_drift_main(dirname(__DIR__)));
}
