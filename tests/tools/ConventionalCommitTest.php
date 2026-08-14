<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/tools/conventional-commit.php';

/*
 * The gate that stops a malformed message from ever reaching the changelog. Titles are injected as
 * data; the workflow only ever hands this grammar the pull request's own title and body.
 */

it('accepts a well-formed title', function (string $title, string $body = '') {
    expect(conventional_title_problems($title, $body))->toBe([]);
})->with([
    'plain' => ['feat(core): add an emitter'],
    'no scope' => ['chore: bump the lock file'],
    'squashed reference' => ['fix(laravel): read the renamed middleware (#123)'],
    'breaking, both halves' => ['feat(core)!: rename the identity prefix', "Why.\n\nBREAKING CHANGE: `op:` is now `ope:`."],
    'breaking with a folded footer' => ['feat(core)!: rename the identity prefix', "BREAKING CHANGE: `op:` is now\n`ope:` everywhere."],
    'hyphenated footer spelling' => ['refactor(repo)!: move the packages', 'BREAKING-CHANGE: paths moved.'],
    'a description with a colon in it' => ['docs(website): errors: a new page'],
]);

it('accepts every type on the allow-list', function (string $type) {
    expect(conventional_title_problems($type.'(core): a change'))->toBe([]);
})->with(CONVENTIONAL_TYPES);

it('accepts every scope on the allow-list and maps it to its package', function (string $scope, ?string $package) {
    expect(conventional_title_problems('feat('.$scope.'): a change'))->toBe([])
        ->and(conventional_package($scope))->toBe($package)
        ->and(conventional_scope_allowed($scope))->toBeTrue();
})->with([
    ['core', 'php/core'],
    ['attributes', 'php/attributes'],
    ['laravel', 'php/laravel'],
    ['inference-phpstan', 'php/inference-phpstan'],
    ['repo', null],
    ['website', null],
    ['ci', null],
]);

it('rejects a scope outside the allow-list, naming the allowed set', function (string $title) {
    $problems = conventional_title_problems($title);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('is not in the allow-list')
        ->and($problems[0])->toContain('attributes, ci, core, inference-phpstan, laravel, repo, website');
})->with([
    'an area that is not a scope' => ['feat(eloquent): infer casts'],
    // The pre-gate history's own mistake: the type typed into the scope slot.
    'a type in the scope slot' => ['chore(fix): the website build'],
    'two scopes at once' => ['refactor(core,inference-phpstan): move the grammar'],
    'an unknown package' => ['feat(python-core): a second language'],
]);

it('rejects a type that is not conventional', function () {
    $problems = conventional_title_problems('feature(core): add an emitter');

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('type `feature` is not a conventional type')
        ->and($problems[0])->toContain('build, chore, ci, docs, feat, fix, perf, refactor, revert, style, test');
});

it('rejects a title with no conventional shape at all', function () {
    // The other real pre-gate message: no type, no scope, no colon.
    $problems = conventional_title_problems('Update symfony/yaml version constraint');

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('has no conventional type')
        ->and($problems[0])->toContain('attributes, ci, core, inference-phpstan, laravel, repo, website');
});

it('points at the missing colon when the rest looks conventional', function () {
    $problems = conventional_title_problems('chore(fix) website production build');

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('missing the colon after the type');
});

it('rejects an empty scope and an empty title', function (string $title, string $expected) {
    $problems = conventional_title_problems($title);

    expect($problems)->toHaveCount(1)->and($problems[0])->toContain($expected);
})->with([
    'empty brackets' => ['feat(): add an emitter', 'the scope is empty'],
    'empty title' => ['', 'the title is empty'],
    'whitespace only' => ['   ', 'the title is empty'],
]);

it('rejects a description that ends with a full stop', function () {
    expect(conventional_title_problems('feat(core): add an emitter.'))
        ->toBe(['the description must not end with a full stop.']);
});

it('cross-checks the two halves of a breaking change', function (string $title, string $body, string $expected) {
    $problems = conventional_title_problems($title, $body);

    expect($problems)->toHaveCount(1)->and($problems[0])->toContain($expected);
})->with([
    // Either half alone is how a breaking change silently lands as a routine entry.
    'bang without a footer' => ['feat(core)!: rename the identity prefix', 'Why it changed.', 'no `BREAKING CHANGE:` footer'],
    'footer without a bang' => ['feat(core): rename the identity prefix', 'BREAKING CHANGE: `op:` is now `ope:`.', 'is not marked `!`'],
    'an empty footer' => ['feat(core)!: rename the identity prefix', 'BREAKING CHANGE:', 'footer has no text'],
]);

it('reads a breaking footer out of a body, or reports there is none', function (string $body, ?string $expected) {
    expect(conventional_breaking_footer($body))->toBe($expected);
})->with([
    'absent' => ["Just prose.\nOver two lines.", null],
    'present' => ['BREAKING CHANGE: it moved.', 'it moved.'],
    'after prose' => ["Why.\n\nBREAKING CHANGE: it moved.", 'it moved.'],
    'folded over lines' => ["BREAKING CHANGE: it moved\nand then moved again.", 'it moved and then moved again.'],
    'stops at the blank line' => ["BREAKING CHANGE: it moved.\n\nSigned-off-by: Someone", 'it moved.'],
    'empty' => ['BREAKING CHANGE:', ''],
]);

it('runs as a script, exiting on the verdict', function (string $title, string $body, int $status, string $expected) {
    // The CLI half: the environment plumbing and the exit code the workflow reads, plus proof that
    // requiring the file (as every test above does) really does not run it.
    $command = sprintf(
        'PR_TITLE=%s PR_BODY=%s php %s 2>&1',
        escapeshellarg($title),
        escapeshellarg($body),
        escapeshellarg(dirname(__DIR__, 2).'/tools/pr-title-lint.php'),
    );

    $output = [];
    $actual = 0;
    exec($command, $output, $actual);

    expect($actual)->toBe($status)
        ->and(implode("\n", $output))->toContain($expected);
})->with([
    'valid' => ['feat(core): add an emitter', '', 0, 'pr-title-lint: OK'],
    'invalid' => ['feat(nope): add an emitter', '', 1, 'is not in the allow-list'],
    'breaking, both halves' => ['feat(core)!: rename it', 'BREAKING CHANGE: it moved.', 0, 'pr-title-lint: OK'],
    'no title at all' => ['', '', 1, 'the title is empty'],
]);

it('splits the reference GitHub appends to a squashed title', function (string $subject, string $bare, ?int $reference) {
    expect(conventional_split_reference($subject))->toBe([$bare, $reference]);
})->with([
    'squashed' => ['feat(core): add an emitter (#123)', 'feat(core): add an emitter', 123],
    'not squashed' => ['feat(core): add an emitter', 'feat(core): add an emitter', null],
    'a hash mid-title' => ['fix(core): handle #123 in a path', 'fix(core): handle #123 in a path', null],
    'not a number' => ['feat(core): add an emitter (#abc)', 'feat(core): add an emitter (#abc)', null],
]);
