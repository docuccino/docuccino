<?php

declare(strict_types=1);

require_once __DIR__.'/conventional-commit.php';

/*
 * Commit-driven changelog generator.
 *
 * Reads the commit subjects since a baseline tag and writes the whole changelog set from scratch
 * every time: one `php/<pkg>/CHANGELOG.md` per package (so each rides the subtree split into its
 * own repository and onto Packagist) plus one aggregate page for the docs site. The files are
 * generated artifacts — the commit messages are the source.
 *
 * Deterministic, like everything else here: the same commit range produces byte-identical files.
 * That is also why no entry carries a date — the pending release's date would change on every
 * push to `main` and churn the release pull request for no information.
 *
 * Pre-baseline history predates the title gate (it contains merge commits, `chore(fix)` with the
 * type typed into the scope slot, and one subject with no type at all), so it is deliberately not
 * parsed. Anything unparseable inside the range is skipped with a warning rather than guessed at.
 *
 * Usage:
 *   composer changelog                       # write every changelog file
 *   php tools/changelog.php --print-version  # the version the pending changes would release as
 *   php tools/changelog.php --stdout         # the aggregate page, written nowhere
 *   php tools/changelog.php --baseline=v1.0.0
 */

/**
 * The first release the commit gate covers. Everything up to and including this tag is
 * best-effort history, not changelog material.
 */
const CHANGELOG_BASELINE = 'v0.1.2';

const CHANGELOG_REPOSITORY_URL = 'https://github.com/docuccino/docuccino';

const CHANGELOG_AGGREGATE_PATH = 'website/src/content/docs/changelog.md';

/** Section heading per bucket, in render order. Breaking changes come first, always. */
const CHANGELOG_SECTIONS = [
    'breaking' => 'Breaking changes',
    'feat' => 'Features',
    'fix' => 'Bug fixes',
    'perf' => 'Performance',
];

/** The types that produce an entry on their own. Anything with a breaking marker joins them. */
const CHANGELOG_USER_FACING_TYPES = ['feat', 'fix', 'perf'];

/**
 * Turn raw commit records into changelog entries, plus the warnings a human should see.
 *
 * Merge commits and the release pull request's own `Release vX.Y.Z` squash are skipped silently;
 * any other subject that does not parse is skipped loudly. A scope outside the allow-list still
 * renders on the aggregate page but ships with no package.
 *
 * @param  list<array{subject: string, body?: string, parents?: int}>  $commits
 * @return array{changes: list<array{type: string, scope: string|null, package: string|null, breaking: bool, note: string|null, description: string, reference: int|null}>, warnings: list<string>}
 */
function changelog_collect(array $commits): array
{
    $changes = [];
    $warnings = [];

    foreach ($commits as $commit) {
        $subject = $commit['subject'];
        $body = $commit['body'] ?? '';

        if (conventional_is_merge($subject, $commit['parents'] ?? 1) || conventional_is_release_subject($subject)) {
            continue;
        }

        $parsed = conventional_parse($subject);
        if ($parsed === null) {
            $warnings[] = sprintf('skipped a commit whose subject is not conventional: %s', trim($subject));

            continue;
        }

        $note = conventional_breaking_footer($body);
        $breaking = $parsed['breaking'] || $note !== null;

        if (! $breaking && ! in_array($parsed['type'], CHANGELOG_USER_FACING_TYPES, true)) {
            continue;
        }

        $scope = $parsed['scope'];
        if ($scope !== null && ! conventional_scope_allowed($scope)) {
            $warnings[] = sprintf(
                'scope `%s` maps to no package, so "%s" reaches the aggregate page only — allowed scopes: %s',
                $scope,
                $parsed['description'],
                conventional_scope_list(),
            );
        }

        $changes[] = [
            'type' => $parsed['type'],
            'scope' => $scope,
            'package' => conventional_package($scope),
            'breaking' => $breaking,
            'note' => ($note === null || $note === '') ? null : $note,
            'description' => $parsed['description'],
            'reference' => $parsed['reference'],
        ];
    }

    return ['changes' => $changes, 'warnings' => array_values(array_unique($warnings))];
}

/**
 * The version the given changes release as, or null when nothing user-facing is pending.
 *
 * Below 1.0.0 a breaking change moves the minor and everything else the patch — semver's own
 * allowance for 0.x, and what a `^0.1` constraint already assumes.
 *
 * @param  list<array{type: string, breaking: bool, ...}>  $changes
 */
function changelog_next_version(string $current, array $changes): ?string
{
    if ($changes === []) {
        return null;
    }

    $parts = array_map(intval(...), explode('.', ltrim($current, 'v')));
    [$major, $minor, $patch] = array_pad(array_slice($parts, 0, 3), 3, 0);

    $breaking = false;
    $feature = false;
    foreach ($changes as $change) {
        $breaking = $breaking || $change['breaking'];
        $feature = $feature || $change['type'] === 'feat';
    }

    if ($major === 0) {
        return $breaking ? sprintf('v0.%d.0', $minor + 1) : sprintf('v0.%d.%d', $minor, $patch + 1);
    }

    if ($breaking) {
        return sprintf('v%d.0.0', $major + 1);
    }

    return $feature ? sprintf('v%d.%d.0', $major, $minor + 1) : sprintf('v%d.%d.%d', $major, $minor, $patch + 1);
}

/**
 * Bucket changes into the render sections. A breaking change appears only in `breaking`, never
 * twice.
 *
 * @param  list<array{type: string, breaking: bool, ...}>  $changes
 * @return array<string, list<array<string, mixed>>>
 */
function changelog_bucket(array $changes): array
{
    /** @var array<string, list<array<string, mixed>>> $buckets */
    $buckets = array_fill_keys(array_keys(CHANGELOG_SECTIONS), []);

    foreach ($changes as $change) {
        $bucket = $change['breaking'] ? 'breaking' : $change['type'];
        if (! array_key_exists($bucket, $buckets)) {
            continue;
        }

        $buckets[$bucket][] = $change;
    }

    return $buckets;
}

/**
 * One entry line, plus a nested line for a breaking note. `$withScope` is off for a per-package
 * file, where the scope would repeat the package on every line.
 *
 * @param  array{scope: string|null, description: string, reference: int|null, note: string|null, ...}  $change
 */
function changelog_entry(array $change, bool $withScope): string
{
    $prefix = ($withScope && $change['scope'] !== null) ? sprintf('**%s**: ', $change['scope']) : '';

    $reference = $change['reference'] === null
        ? ''
        : sprintf(' ([#%d](%s/pull/%d))', $change['reference'], CHANGELOG_REPOSITORY_URL, $change['reference']);

    $line = sprintf('- %s%s%s', $prefix, $change['description'], $reference);

    return $change['note'] === null ? $line : $line."\n  - ".$change['note'];
}

/**
 * One release section: a version heading and its non-empty buckets. Empty string when the release
 * has nothing for this reader (a package untouched by that version).
 *
 * @param  list<array<string, mixed>>  $changes
 */
function changelog_release(string $version, array $changes, bool $withScope): string
{
    if ($changes === []) {
        return '';
    }

    $out = '## '.$version."\n";

    foreach (changelog_bucket($changes) as $bucket => $entries) {
        if ($entries === []) {
            continue;
        }

        $out .= "\n### ".CHANGELOG_SECTIONS[$bucket]."\n\n";
        foreach ($entries as $entry) {
            $out .= changelog_entry($entry, $withScope)."\n";
        }
    }

    return $out;
}

/**
 * Every changelog file, as `relative path => contents`. Releases arrive newest first; each is
 * rendered for each package and for the aggregate page.
 *
 * @param  list<array{version: string, changes: list<array<string, mixed>>}>  $releases
 * @return array<string, string>
 */
function changelog_documents(array $releases, string $baseline = CHANGELOG_BASELINE): array
{
    $documents = [];

    foreach (CONVENTIONAL_SCOPES as $scope => $directory) {
        if ($directory === null) {
            continue;
        }

        $body = '';
        foreach ($releases as $release) {
            $changes = array_values(array_filter(
                $release['changes'],
                static fn (array $change): bool => $change['package'] === $directory,
            ));

            $section = changelog_release($release['version'], $changes, false);
            $body .= $section === '' ? '' : ($body === '' ? '' : "\n").$section;
        }

        $documents[$directory.'/CHANGELOG.md'] = changelog_package_header($scope, $baseline).changelog_body($body);
    }

    $body = '';
    foreach ($releases as $release) {
        $section = changelog_release($release['version'], $release['changes'], true);
        $body .= $section === '' ? '' : ($body === '' ? '' : "\n").$section;
    }

    $documents[CHANGELOG_AGGREGATE_PATH] = changelog_aggregate_header($baseline).changelog_body($body);

    return $documents;
}

/**
 * A rendered body, or the honest placeholder when a reader's slice of the range is empty.
 *
 * @internal
 */
function changelog_body(string $body): string
{
    return $body === '' ? "_No user-facing changes yet._\n" : $body;
}

/** @internal */
function changelog_package_header(string $scope, string $baseline): string
{
    return sprintf(
        <<<'MARKDOWN'
        # Changelog

        <!-- Generated by tools/changelog.php in the docuccino monorepo. Do not edit: fix the commit
             message, not this file. -->

        User-facing changes to `%s` — features, fixes, performance work and anything breaking —
        taken from the commit messages scoped `%s`. Entries begin after %s; older history is in
        the [monorepo](%s) git log.


        MARKDOWN,
        CONVENTIONAL_PACKAGE_NAMES[$scope],
        $scope,
        $baseline,
        CHANGELOG_REPOSITORY_URL,
    );
}

/** @internal */
function changelog_aggregate_header(string $baseline): string
{
    return sprintf(
        <<<'MARKDOWN'
        ---
        title: Changelog
        description: Every user-facing change in Docuccino — breaking changes, features, fixes and performance work — for all four packages.
        ---

        **This page is generated** from the commit history by `tools/changelog.php` — do not edit it
        by hand, fix the commit message instead.

        Every user-facing change across the four packages. The bold prefix is the commit's scope:
        **core**, **attributes**, **laravel** and **inference-phpstan** are the packages, and the
        rest (**website**, **repo**, **ci**) ship no package. Entries begin after %s; older history
        is in the [monorepo](%s) git log.

        Each package repository also carries its own `CHANGELOG.md` with just its entries.


        MARKDOWN,
        $baseline,
        CHANGELOG_REPOSITORY_URL,
    );
}

/**
 * Run git and return its output, or die with git's own message.
 *
 * @internal
 */
function changelog_git(string ...$arguments): string
{
    $command = 'git '.implode(' ', array_map(escapeshellarg(...), $arguments)).' 2>&1';

    $output = [];
    $status = 0;
    exec($command, $output, $status);

    if ($status !== 0) {
        fwrite(STDERR, sprintf("changelog: `%s` failed:\n%s\n", $command, implode("\n", $output)));
        exit(1);
    }

    return implode("\n", $output);
}

/**
 * The commits in a range, newest first, as the records changelog_collect() reads.
 *
 * @return list<array{subject: string, body: string, parents: int}>
 *
 * @internal
 */
function changelog_commits(string $range): array
{
    $log = changelog_git('log', '--format=%x1e%P%x1f%s%x1f%b', $range);

    $commits = [];
    foreach (explode("\x1e", $log) as $record) {
        if (trim($record) === '') {
            continue;
        }

        $fields = array_pad(explode("\x1f", $record), 3, '');
        $parents = array_filter(explode(' ', trim($fields[0])));

        $commits[] = [
            'subject' => trim($fields[1]),
            'body' => $fields[2],
            'parents' => count($parents),
        ];
    }

    return $commits;
}

/**
 * The releases to render: every tag after the baseline, newest first, with the pending range on
 * top when it has anything in it.
 *
 * @return array{releases: list<array{version: string, changes: list<array<string, mixed>>}>, warnings: list<string>, next: string|null}
 *
 * @internal
 */
function changelog_read_releases(string $baseline): array
{
    // Reachable from HEAD but not from the baseline — history, not string comparison, so a branch,
    // a SHA or `HEAD` works as a baseline just as well as a tag does.
    $listed = changelog_git('tag', '--merged', 'HEAD', '--no-merged', $baseline, '--sort=v:refname', '-l', 'v*');

    $tags = [];
    foreach (explode("\n", $listed) as $tag) {
        if (trim($tag) !== '') {
            $tags[] = trim($tag);
        }
    }

    $releases = [];
    $warnings = [];
    $previous = $baseline;

    foreach ($tags as $tag) {
        $collected = changelog_collect(changelog_commits($previous.'..'.$tag));
        $releases[] = ['version' => $tag, 'changes' => $collected['changes']];
        $warnings = [...$warnings, ...$collected['warnings']];
        $previous = $tag;
    }

    $pending = changelog_collect(changelog_commits($previous.'..HEAD'));
    $warnings = [...$warnings, ...$pending['warnings']];
    $next = changelog_next_version($previous, $pending['changes']);

    if ($next !== null) {
        $releases[] = ['version' => $next, 'changes' => $pending['changes']];
    }

    return [
        'releases' => array_reverse($releases),
        'warnings' => array_values(array_unique($warnings)),
        'next' => $next,
    ];
}

/** @internal */
function changelog_main(): int
{
    $baseline = CHANGELOG_BASELINE;
    $mode = 'write';

    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
        if (! is_string($argument)) {
            continue;
        }

        if (str_starts_with($argument, '--baseline=')) {
            $baseline = substr($argument, strlen('--baseline='));
        } elseif ($argument === '--print-version') {
            $mode = 'version';
        } elseif ($argument === '--stdout') {
            $mode = 'stdout';
        } else {
            fwrite(STDERR, sprintf("changelog: unknown argument %s (see the header of %s)\n", $argument, basename(__FILE__)));

            return 1;
        }
    }

    // Every path below is repository-relative, and git must run against this repository whatever
    // the caller's cwd.
    chdir(dirname(__DIR__));

    $read = changelog_read_releases($baseline);

    if ($mode === 'version') {
        echo $read['next'] ?? '', "\n";

        return 0;
    }

    // Warnings go to stderr, so `--print-version`'s single stdout line stays parseable.
    foreach ($read['warnings'] as $warning) {
        fwrite(STDERR, 'changelog: '.$warning."\n");
    }

    $documents = changelog_documents($read['releases'], $baseline);

    if ($mode === 'stdout') {
        echo $documents[CHANGELOG_AGGREGATE_PATH];

        return 0;
    }

    printf("changelog: baseline %s, pending release %s\n", $baseline, $read['next'] ?? 'none');

    foreach ($documents as $path => $contents) {
        $existing = is_file($path) ? file_get_contents($path) : false;
        if ($existing === $contents) {
            printf("  unchanged  %s\n", $path);

            continue;
        }

        file_put_contents($path, $contents);
        printf("  written    %s\n", $path);
    }

    return 0;
}

if (conventional_is_entry_script(__FILE__)) {
    exit(changelog_main());
}
