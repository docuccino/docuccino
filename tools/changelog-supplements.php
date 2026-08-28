<?php

declare(strict_types=1);

/*
 * Curated changelog entries for an already-released version whose commit record was destroyed.
 *
 * The commit messages are the source and stay the source — this table is the ONE exception, and it
 * is deliberately hard to use for anything else. A supplement is only ever read for a version that
 * is already TAGGED: for the pending range the fix is still to write the commit message correctly,
 * and tools/changelog.php refuses a supplement that names the pending release, a version that is
 * not a tag in this repository, or a pull request the commits now carry themselves. Each entry is
 * written as the commit message it would have been and travels the same parser, the same buckets
 * and the same renderer, so nothing here can look different in the output from a real commit.
 *
 * A supplement is a repair, never a convenience: the `reason` says what happened to the source, and
 * `tools/changelog.php` prints it on every run so the exception stays visible.
 */

const CHANGELOG_SUPPLEMENTS = [
    'v0.11.0' => [
        'reason' => 'v0.11.0 shipped thirteen pull requests and its commit record carries five. '
            .'#266–#273 were developed on a stack based on #263\'s branch, so their commits were '
            .'already on that branch when it squash-merged: GitHub collapsed all forty-one into a '
            .'single commit carrying #263\'s message alone, taking #271\'s `BREAKING CHANGE:` footer '
            .'with it. The code all shipped; only the record was destroyed, and a released tag\'s '
            .'messages cannot be rewritten. The entries below are the pull request titles and '
            .'#271\'s footer, restored verbatim from GitHub.',
        'entries' => [
            [
                'subject' => "fix(core): read a parameter's type in the grammar the validator reads (#266)",
            ],
            [
                'subject' => 'fix(core): read an ambiguous empty body as the container the contract accepts (#267)',
            ],
            [
                'subject' => 'fix(core): follow a $ref wherever the grammar permits one (#268)',
            ],
            [
                'subject' => 'fix(laravel): read a #[BodyParameter] name as a field path, not a map key (#269)',
            ],
            [
                'subject' => 'fix(laravel): read a bare array rule as either container, not as a list (#270)',
            ],
            [
                'subject' => 'fix(laravel)!: record an example only where an assertion names it (#271)',
                'body' => 'BREAKING CHANGE: `ApiContract::record()` no longer publishes an example for '
                    ."every checked response. An exchange is recorded only where the assertion names the\n"
                    ."scenario — `assertValidExchange(recordAs: 'with-tags')` — so a suite that records today\n"
                    ."records nothing tomorrow until its call sites name what is worth publishing. Committed\n"
                    ."recordings already on disk are still read and still publish; each build reports them once\n"
                    ."as `examples.recording-unnamed`, since no run will refresh them. An explicit `recordAs: ''`\n"
                    .'now raises rather than being ignored.',
            ],
            [
                'subject' => 'feat(core): hold a security requirement to the schemes the document publishes (#272)',
            ],
            [
                'subject' => 'fix(core): resolve a Reference Object before the diff decides what changed (#273)',
            ],
        ],
    ],
];
