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

it('skips the release pull request its own squash commit, silently', function (string $subject, bool $silent) {
    $collected = changelog_collect([['subject' => $subject]]);

    expect($collected['changes'])->toBe([])
        ->and($collected['warnings'])->toHaveCount($silent ? 0 : 1);
})->with([
    // Otherwise every release would leave a warning behind for good.
    'squashed' => ['Release v0.1.3 (#104)', true],
    'unsquashed' => ['Release v1.2.3', true],
    'not a release at all' => ['Release the kraken', false],
]);

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

/*
 * Supplements — the one exception to "the commit messages are the source", for an already-released
 * version whose commit record was destroyed and can no longer be fixed. The mechanism is proved on
 * synthetic tables; the last test in this file reads the REAL table against this repository's real
 * tags, which is the half that catches a supplement going stale.
 */

it('folds a supplement for a shipped release in beside the commits, in section order', function () {
    $releases = [['version' => 'v1.1.0', 'changes' => changelog_collect([
        ['subject' => 'fix(core): a recorded fix (#10)'],
    ])['changes']]];

    $supplemented = changelog_supplemented($releases, 'v1.2.0', ['v1.0.0', 'v1.1.0'], [
        'v1.1.0' => [
            'reason' => 'the stack squashed into one commit and took the rest of the messages with it.',
            'entries' => [
                ['subject' => 'feat(core): a lost feature (#11)'],
                ['subject' => 'fix(laravel)!: a lost break (#12)', 'body' => 'BREAKING CHANGE: the method is gone.'],
            ],
        ],
    ]);

    $aggregate = changelog_documents($supplemented['releases'])['website/src/content/docs/changelog.md'];

    expect($supplemented['notes'])->toHaveCount(1)
        ->and($supplemented['notes'][0])->toContain('supplemented v1.1.0 with 2 curated entries')
        // The reason travels with the run, so the exception is visible every time it is used.
        ->and($supplemented['notes'][0])->toContain('took the rest of the messages with it')
        // Breaking first, then the buckets in their usual order, and the supplemented entries are
        // rendered by the same code as the commit-derived one above them.
        ->and($aggregate)->toContain(<<<'MARKDOWN'
        ## v1.1.0

        ### Breaking changes

        - **laravel**: a lost break ([#12](https://github.com/docuccino/docuccino/pull/12))
          - the method is gone.

        ### Features

        - **core**: a lost feature ([#11](https://github.com/docuccino/docuccino/pull/11))

        ### Bug fixes

        - **core**: a recorded fix ([#10](https://github.com/docuccino/docuccino/pull/10))
        MARKDOWN);
});

it('routes a supplemented entry to its own package file, like any other entry', function () {
    $supplemented = changelog_supplemented(
        [['version' => 'v1.1.0', 'changes' => []]],
        null,
        ['v1.1.0'],
        ['v1.1.0' => [
            'reason' => 'the record was destroyed by a squash.',
            'entries' => [
                ['subject' => 'feat(core): a lost feature (#11)'],
                ['subject' => 'fix(laravel): a lost fix (#12)'],
            ],
        ]],
    );

    $documents = changelog_documents($supplemented['releases']);

    expect($documents['php/core/CHANGELOG.md'])->toContain('- a lost feature ([#11]')
        ->and($documents['php/core/CHANGELOG.md'])->not->toContain('a lost fix')
        ->and($documents['php/laravel/CHANGELOG.md'])->toContain('- a lost fix ([#12]')
        ->and($documents['php/laravel/CHANGELOG.md'])->not->toContain('a lost feature')
        ->and($documents['php/attributes/CHANGELOG.md'])->toContain('_No user-facing changes yet._')
        ->and($documents['php/inference-phpstan/CHANGELOG.md'])->toContain('_No user-facing changes yet._');
});

it('refuses a supplement that would make it a general hand-edit backdoor', function (array $supplements, string $expected) {
    $releases = [['version' => 'v1.1.0', 'changes' => changelog_collect([
        ['subject' => 'fix(core): a recorded fix (#10)'],
    ])['changes']]];

    expect(static fn (): array => changelog_supplemented($releases, 'v1.2.0', ['v1.0.0', 'v1.1.0'], $supplements))
        ->toThrow(RuntimeException::class, $expected);
})->with([
    // The line that must never be crossed: for the range still being written, the fix is the
    // commit message.
    'the pending release' => [
        ['v1.2.0' => ['reason' => 'because I said so.', 'entries' => [['subject' => 'feat(core): a feature (#11)']]]],
        'v1.2.0 is the pending release, not a shipped one',
    ],
    'a version that was never tagged' => [
        ['v9.9.9' => ['reason' => 'a typo.', 'entries' => [['subject' => 'feat(core): a feature (#11)']]]],
        'v9.9.9 is not a release tag in this repository',
    ],
    'no reason for the repair' => [
        ['v1.1.0' => ['reason' => "  \n", 'entries' => [['subject' => 'feat(core): a feature (#11)']]]],
        'the v1.1.0 supplement carries no reason',
    ],
    'no entries at all' => [
        ['v1.1.0' => ['reason' => 'the record was destroyed.', 'entries' => []]],
        'the v1.1.0 supplement lists no entries',
    ],
    // The drift guard: history caught up, so the supplement has to go.
    'a pull request the commits now carry' => [
        ['v1.1.0' => ['reason' => 'the record was destroyed.', 'entries' => [['subject' => 'fix(core): a recorded fix (#10)']]]],
        'the v1.1.0 supplement entry #10 is in the commit record already ("a recorded fix")',
    ],
    'the same pull request twice' => [
        ['v1.1.0' => ['reason' => 'the record was destroyed.', 'entries' => [
            ['subject' => 'feat(core): a feature (#11)'],
            ['subject' => 'fix(core): the same pull request (#11)'],
        ]]],
        'the v1.1.0 supplement lists #11 twice',
    ],
    // Without a reference there is no identity to check for duplication, so it is refused.
    'no pull request reference' => [
        ['v1.1.0' => ['reason' => 'the record was destroyed.', 'entries' => [['subject' => 'feat(core): a feature']]]],
        'carries no `(#N)` pull request reference',
    ],
    'a subject the title gate would reject' => [
        ['v1.1.0' => ['reason' => 'the record was destroyed.', 'entries' => [['subject' => 'feat(core) a feature (#11)']]]],
        'is not a valid message: the title is missing the colon after the type',
    ],
    'a scope outside the allow-list' => [
        ['v1.1.0' => ['reason' => 'the record was destroyed.', 'entries' => [['subject' => 'feat(tooling): a feature (#11)']]]],
        'is not a valid message: scope `tooling` is not in the allow-list',
    ],
    // The `!`/footer pairing the pull request title gate enforces holds for a restored entry too —
    // it is the half that was lost when v0.11.0's stack collapsed.
    'a break with no footer to explain it' => [
        ['v1.1.0' => ['reason' => 'the record was destroyed.', 'entries' => [['subject' => 'feat(core)!: a break (#11)']]]],
        'the body has no `BREAKING CHANGE:` footer',
    ],
    'a footer with no break marked' => [
        ['v1.1.0' => ['reason' => 'the record was destroyed.', 'entries' => [
            ['subject' => 'feat(core): a break (#11)', 'body' => 'BREAKING CHANGE: it moved.'],
        ]]],
        'the title is not marked `!`',
    ],
    'a type that produces no entry' => [
        ['v1.1.0' => ['reason' => 'the record was destroyed.', 'entries' => [['subject' => 'chore(core): tidy up (#11)']]]],
        'produces no changelog entry — a supplement restores user-facing entries only',
    ],
]);

it('reports a supplement the baseline leaves out of range rather than dropping it', function () {
    $supplemented = changelog_supplemented([['version' => 'v1.1.0', 'changes' => []]], null, ['v1.0.0', 'v1.1.0'], [
        'v1.0.0' => [
            'reason' => 'the record was destroyed.',
            // Still read, so a malformed or stale entry cannot hide behind a baseline that happens
            // not to render its version.
            'entries' => [['subject' => 'feat(core): a lost feature (#11)']],
        ],
    ]);

    expect($supplemented['notes'])->toBe([
        'the v1.0.0 supplement is outside the rendered range and was not applied — check the baseline.',
    ])->and($supplemented['releases'])->toBe([['version' => 'v1.1.0', 'changes' => []]]);
});

it('leaves a release with no supplement exactly as the commits produced it', function () {
    $releases = [
        ['version' => 'v1.1.0', 'changes' => changelog_collect([['subject' => 'fix(core): a recorded fix (#10)']])['changes']],
        ['version' => 'v1.0.0', 'changes' => changelog_collect([['subject' => 'feat(laravel): an older feature (#4)']])['changes']],
    ];

    $supplemented = changelog_supplemented($releases, null, ['v1.0.0', 'v1.1.0'], [
        'v1.1.0' => ['reason' => 'the record was destroyed.', 'entries' => [['subject' => 'feat(core): a lost feature (#11)']]],
    ]);

    $before = changelog_documents($releases);
    $after = changelog_documents($supplemented['releases']);

    // v1.0.0's section is byte-identical, and so is the whole of the package it never touched.
    expect(explode("## v1.0.0\n", $after['website/src/content/docs/changelog.md'], 2)[1])
        ->toBe(explode("## v1.0.0\n", $before['website/src/content/docs/changelog.md'], 2)[1])
        ->and($after['php/laravel/CHANGELOG.md'])->toBe($before['php/laravel/CHANGELOG.md'])
        ->and($after['php/attributes/CHANGELOG.md'])->toBe($before['php/attributes/CHANGELOG.md']);
});

it('renders byte-identical documents for the same supplement', function () {
    $supplement = ['v1.1.0' => [
        'reason' => 'the record was destroyed.',
        'entries' => [
            ['subject' => 'fix(laravel)!: a lost break (#12)', 'body' => 'BREAKING CHANGE: the method is gone.'],
            ['subject' => 'feat(core): a lost feature (#11)'],
        ],
    ]];

    $render = static fn (): array => changelog_documents(changelog_supplemented(
        [['version' => 'v1.1.0', 'changes' => changelog_collect([['subject' => 'fix(core): a recorded fix (#10)']])['changes']]],
        null,
        ['v1.1.0'],
        $supplement,
    )['releases']);

    expect($render())->toBe($render());
});

it('holds the shipped supplement table to this repository real history', function () {
    // The one test here that reads real git and the real table, because that is the only thing a
    // stale supplement can be measured against: a version that stops being a tag, or a pull request
    // the commits start carrying themselves, fails the generator rather than rendering quietly.
    $command = sprintf('php %s --stdout 2>/dev/null', escapeshellarg(dirname(__DIR__, 2).'/tools/changelog.php'));

    $output = [];
    $status = 0;
    exec($command, $output, $status);

    $aggregate = implode("\n", $output);
    $section = explode("\n## v0.10.5", explode("## v0.11.0\n", $aggregate, 2)[1] ?? '', 2)[0];
    $entries = preg_grep('/^- \*\*/', explode("\n", $section)) ?: [];

    expect($status)->toBe(0)
        // v0.11.0 shipped thirteen pull requests; five survive in the commit record, eight are
        // supplemented. A number lower than thirteen means the repair has come undone.
        ->and(CHANGELOG_SUPPLEMENTS['v0.11.0']['entries'])->toHaveCount(8)
        ->and($entries)->toHaveCount(13)
        ->and($section)->toContain('### Breaking changes')
        // The footer that existed nowhere in git, restored where a consumer will actually read it.
        ->and($section)->toContain('- **laravel**: record an example only where an assertion names it ([#271](https://github.com/docuccino/docuccino/pull/271))')
        ->and($section)->toContain('  - `ApiContract::record()` no longer publishes an example for every checked response.');

    foreach (array_keys(CHANGELOG_SUPPLEMENTS) as $version) {
        expect(trim(shell_exec(sprintf('git -C %s tag -l %s', escapeshellarg(dirname(__DIR__, 2)), escapeshellarg($version))) ?? ''))
            ->toBe($version);
    }
});
