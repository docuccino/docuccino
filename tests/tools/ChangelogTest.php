<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/tools/changelog.php';

/*
 * Commit lists are injected as DATA, never read out of this repository's git history: the generator
 * has to be provable on a range that contains a malformed subject and a merge commit, and a test
 * that shelled out to `git log` would prove whatever today's history happens to be.
 */

it('routes every conventional type to the section it belongs in', function (string $type, ?string $section) {
    $collected = changelog_collect([
        ['subject' => $type.'(core): a change'],
    ]);

    $document = changelog_documents([['version' => 'v1.0.0', 'changes' => $collected['changes']]])['php/core/CHANGELOG.md'];

    if ($section === null) {
        expect($collected['changes'])->toBe([])
            ->and($document)->toContain('_No user-facing changes yet._');

        return;
    }

    expect($document)->toContain('### '.$section)
        ->and($document)->toContain('- a change');
})->with([
    // Every entry in the type table, and what each does or does not produce.
    ['feat', 'Features'],
    ['fix', 'Bug fixes'],
    ['perf', 'Performance'],
    ['build', null],
    ['chore', null],
    ['ci', null],
    ['docs', null],
    ['refactor', null],
    ['revert', null],
    ['style', null],
    ['test', null],
]);

it('routes each scope to its package and leaves the non-package scopes to the aggregate', function (string $scope, ?string $package) {
    $collected = changelog_collect([['subject' => 'feat('.$scope.'): a change']]);
    $documents = changelog_documents([['version' => 'v1.0.0', 'changes' => $collected['changes']]]);

    expect($collected['changes'][0]['package'])->toBe($package)
        ->and($collected['warnings'])->toBe([])
        ->and($documents['website/src/content/docs/changelog.md'])->toContain('- **'.$scope.'**: a change');

    if ($package !== null) {
        // The package file drops the scope prefix — it would repeat the package on every line.
        expect($documents[$package.'/CHANGELOG.md'])->toContain("\n- a change");
    }

    foreach (['php/core', 'php/attributes', 'php/laravel', 'php/inference-phpstan'] as $other) {
        if ($other !== $package) {
            expect($documents[$other.'/CHANGELOG.md'])->toContain('_No user-facing changes yet._');
        }
    }
})->with([
    ['core', 'php/core'],
    ['attributes', 'php/attributes'],
    ['laravel', 'php/laravel'],
    ['inference-phpstan', 'php/inference-phpstan'],
    ['repo', null],
    ['website', null],
    ['ci', null],
]);

it('promotes anything breaking to its own section, whatever the type', function () {
    $collected = changelog_collect([
        ['subject' => 'feat(core)!: rename the identity prefix', 'body' => "Why.\n\nBREAKING CHANGE: `op:` identities are now `ope:`.\n"],
        ['subject' => 'chore(laravel): drop PHP 8.2', 'body' => 'BREAKING CHANGE: the floor is PHP 8.3.'],
        ['subject' => 'fix(core): a routine fix'],
    ]);

    $aggregate = changelog_documents([['version' => 'v2.0.0', 'changes' => $collected['changes']]])['website/src/content/docs/changelog.md'];

    expect($collected['changes'][0]['breaking'])->toBeTrue()
        // A `chore` carrying a BREAKING CHANGE footer is user-facing after all.
        ->and($collected['changes'][1]['breaking'])->toBeTrue()
        ->and($collected['changes'][2]['breaking'])->toBeFalse()
        ->and($aggregate)->toContain("### Breaking changes\n\n- **core**: rename the identity prefix\n  - `op:` identities are now `ope:`.\n- **laravel**: drop PHP 8.2\n  - the floor is PHP 8.3.\n")
        // …and it is promoted, not duplicated into Features.
        ->and($aggregate)->toContain("### Bug fixes\n\n- **core**: a routine fix\n")
        ->and(substr_count($aggregate, 'rename the identity prefix'))->toBe(1);
});

it('keeps an unmapped scope on the aggregate page and says so', function () {
    $collected = changelog_collect([['subject' => 'feat(tooling): add a generator']]);
    $documents = changelog_documents([['version' => 'v1.0.0', 'changes' => $collected['changes']]]);

    expect($collected['changes'][0]['package'])->toBeNull()
        ->and($collected['warnings'])->toHaveCount(1)
        ->and($collected['warnings'][0])->toContain('scope `tooling` maps to no package')
        ->and($collected['warnings'][0])->toContain('inference-phpstan')
        ->and($documents['website/src/content/docs/changelog.md'])->toContain('- **tooling**: add a generator')
        ->and($documents['php/core/CHANGELOG.md'])->toContain('_No user-facing changes yet._');
});

it('skips a malformed subject with a warning rather than guessing', function (string $subject) {
    $collected = changelog_collect([['subject' => $subject]]);

    expect($collected['changes'])->toBe([])
        ->and($collected['warnings'])->toBe(['skipped a commit whose subject is not conventional: '.$subject]);
})->with([
    // Both real messages from the pre-gate history.
    'the type typed into the scope slot' => ['chore(fix) website production build'],
    'no type at all' => ['Update symfony/yaml version constraint'],
    'nothing after the colon' => ['feat(core):'],
]);

it('tolerates the (#123) suffix a squashed title carries', function () {
    $collected = changelog_collect([['subject' => 'fix(laravel): read the renamed middleware (#123)']]);

    expect($collected['changes'][0]['description'])->toBe('read the renamed middleware')
        ->and($collected['changes'][0]['reference'])->toBe(123)
        ->and(changelog_documents([['version' => 'v1.0.0', 'changes' => $collected['changes']]])['php/laravel/CHANGELOG.md'])
        ->toContain('- read the renamed middleware ([#123](https://github.com/docuccino/docuccino/pull/123))');
});

it('skips merge commits, by parent count and by subject', function () {
    $collected = changelog_collect([
        ['subject' => 'Merge pull request #9 from docuccino/fix/thing', 'parents' => 2],
        ['subject' => "Merge branch 'main' into feat/thing"],
        ['subject' => 'feat(core): the only real commit', 'parents' => 1],
        // A squash merge is a single-parent commit with a conventional subject: not a merge.
        ['subject' => 'fix(core): a squashed pull request (#7)', 'parents' => 1],
    ]);

    expect(array_column($collected['changes'], 'description'))
        ->toBe(['the only real commit', 'a squashed pull request'])
        ->and($collected['warnings'])->toBe([]);
});

it('emits every file for an empty range, saying there is nothing yet', function () {
    $collected = changelog_collect([]);
    $documents = changelog_documents([], 'v0.1.2');

    expect($collected)->toBe(['changes' => [], 'warnings' => []])
        ->and(array_keys($documents))->toBe([
            'php/attributes/CHANGELOG.md',
            'php/core/CHANGELOG.md',
            'php/inference-phpstan/CHANGELOG.md',
            'php/laravel/CHANGELOG.md',
            'website/src/content/docs/changelog.md',
        ]);

    foreach ($documents as $contents) {
        expect($contents)->toContain('_No user-facing changes yet._')
            ->and($contents)->toContain('Entries begin after v0.1.2');
    }
});

it('renders byte-identical documents for the same commits', function () {
    $commits = [
        ['subject' => 'feat(core)!: rename the identity prefix', 'body' => 'BREAKING CHANGE: `op:` is now `ope:`.'],
        ['subject' => 'fix(laravel): read the renamed middleware (#123)'],
        ['subject' => 'perf(inference-phpstan): cache the walk'],
        ['subject' => 'docs(website): a page nobody sees here'],
    ];

    $first = changelog_documents([['version' => 'v0.2.0', 'changes' => changelog_collect($commits)['changes']]]);
    $second = changelog_documents([['version' => 'v0.2.0', 'changes' => changelog_collect($commits)['changes']]]);

    expect($second)->toBe($first);
});

it('renders a whole aggregate page, newest release first', function () {
    $releases = [
        ['version' => 'v0.3.0', 'changes' => changelog_collect([
            ['subject' => 'feat(core)!: rename the identity prefix', 'body' => 'BREAKING CHANGE: `op:` identities are now `ope:`.'],
            ['subject' => 'feat(laravel): expose the openapi-3.0 format (#42)'],
        ])['changes']],
        ['version' => 'v0.2.1', 'changes' => changelog_collect([
            ['subject' => 'fix(core): a routine fix'],
            ['subject' => 'perf(website): ship less JavaScript'],
        ])['changes']],
    ];

    $aggregate = changelog_documents($releases)['website/src/content/docs/changelog.md'];
    [, $body] = explode("own `CHANGELOG.md` with just its entries.\n\n", $aggregate, 2);

    expect($body)->toBe(<<<'MARKDOWN'
        ## v0.3.0

        ### Breaking changes

        - **core**: rename the identity prefix
          - `op:` identities are now `ope:`.

        ### Features

        - **laravel**: expose the openapi-3.0 format ([#42](https://github.com/docuccino/docuccino/pull/42))

        ## v0.2.1

        ### Bug fixes

        - **core**: a routine fix

        ### Performance

        - **website**: ship less JavaScript

        MARKDOWN);
});

it('runs as a script over a real (empty) range', function (array $arguments, int $status, string $expected) {
    // `--baseline=HEAD` leaves an empty range whatever this repository's history is, so the CLI
    // half — argument parsing, the git reads, the exit code — is provable without depending on it.
    $command = sprintf(
        'php %s %s 2>&1',
        escapeshellarg(dirname(__DIR__, 2).'/tools/changelog.php'),
        implode(' ', array_map(escapeshellarg(...), $arguments)),
    );

    $output = [];
    $actual = 0;
    exec($command, $output, $actual);

    expect($actual)->toBe($status)
        ->and(implode("\n", $output))->toContain($expected);
})->with([
    'the aggregate page' => [['--baseline=HEAD', '--stdout'], 0, '_No user-facing changes yet._'],
    'nothing to release' => [['--baseline=HEAD', '--print-version'], 0, ''],
    'an unknown argument' => [['--regenerate'], 1, 'unknown argument --regenerate'],
]);

it('bumps the version from the changes, pre-1.0 and after', function (string $current, array $subjects, ?string $expected) {
    $changes = changelog_collect(array_map(
        static fn (string $subject): array => ['subject' => $subject, 'body' => str_contains($subject, '!') ? 'BREAKING CHANGE: it moved.' : ''],
        $subjects,
    ))['changes'];

    expect(changelog_next_version($current, $changes))->toBe($expected);
})->with([
    'nothing pending' => ['v0.1.2', ['chore(repo): tidy up'], null],
    'pre-1.0 fix' => ['v0.1.2', ['fix(core): a fix'], 'v0.1.3'],
    // Below 1.0.0 a feature is still a patch and only a break moves the minor.
    'pre-1.0 feature' => ['v0.1.2', ['feat(core): a feature'], 'v0.1.3'],
    'pre-1.0 breaking' => ['v0.1.2', ['feat(core)!: a break'], 'v0.2.0'],
    'stable fix' => ['v1.4.2', ['fix(core): a fix'], 'v1.4.3'],
    'stable feature' => ['v1.4.2', ['fix(core): a fix', 'feat(core): a feature'], 'v1.5.0'],
    'stable breaking' => ['v1.4.2', ['feat(core)!: a break'], 'v2.0.0'],
    'a tag without the v' => ['1.4.2', ['fix(core): a fix'], 'v1.4.3'],
]);
