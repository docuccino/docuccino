<?php

declare(strict_types=1);

require_once __DIR__.'/conventional-commit.php';

/*
 * Pull-request title gate.
 *
 * Merges are squash-only and the squash subject is the PR title, so the title IS the commit
 * message that lands on `main` — and the only thing tools/changelog.php will ever read. This
 * checks it against the shared grammar in conventional-commit.php.
 *
 * The title and body arrive through the ENVIRONMENT, never through the workflow's shell: a title
 * is attacker-controlled text, and `${{ github.event.pull_request.title }}` inside a `run:` block
 * would execute it.
 *
 * Usage:
 *   PR_TITLE='feat(core): …' PR_BODY='…' php tools/pr-title-lint.php
 *   php tools/pr-title-lint.php --title='feat(core): …' --body='…'
 */

/**
 * An environment string, empty when unset. `getenv() ?: ''` would swallow a body of "0".
 *
 * @internal
 */
function pr_title_lint_env(string $name): string
{
    $value = getenv($name);

    return $value === false ? '' : $value;
}

/** @internal */
function pr_title_lint_main(): int
{
    $title = pr_title_lint_env('PR_TITLE');
    $body = pr_title_lint_env('PR_BODY');

    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
        if (! is_string($argument)) {
            continue;
        }

        if (str_starts_with($argument, '--title=')) {
            $title = substr($argument, strlen('--title='));
        } elseif (str_starts_with($argument, '--body=')) {
            $body = substr($argument, strlen('--body='));
        } else {
            fwrite(STDERR, sprintf("pr-title-lint: unknown argument %s (see the header of %s)\n", $argument, basename(__FILE__)));

            return 1;
        }
    }

    $problems = conventional_title_problems($title, $body);

    if ($problems === []) {
        printf("pr-title-lint: OK — %s\n", trim($title));

        return 0;
    }

    fwrite(STDERR, sprintf("Pull request title: %s\n\n", trim($title) === '' ? '(empty)' : trim($title)));
    foreach ($problems as $problem) {
        fwrite(STDERR, '  - '.$problem."\n");
    }
    fwrite(STDERR, <<<'TEXT'

        This repository squash-merges, so the title above is the commit message that lands on main
        and the only thing the changelog generator reads. Edit the pull request title (and body, for
        a breaking change) and this check re-runs on its own.

        TEXT);

    return 1;
}

if (conventional_is_entry_script(__FILE__)) {
    exit(pr_title_lint_main());
}
