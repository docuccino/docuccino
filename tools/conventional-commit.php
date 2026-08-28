<?php

declare(strict_types=1);

/*
 * The conventional-commit grammar this repository gates on, in one place.
 *
 * Merges are squash-only, so a pull request's TITLE is the commit message that lands on `main`.
 * That makes the title gate (tools/pr-title-lint.php) and the changelog generator
 * (tools/changelog.php) two readers of one grammar — they share this file rather than each
 * carrying a copy that can drift.
 *
 * Requiring this file has no side effects, so tests can inject synthetic messages.
 */

/**
 * The types a title may use. `feat`, `fix` and `perf` are the user-facing ones the changelog
 * renders; the rest are valid messages that produce no changelog entry.
 */
const CONVENTIONAL_TYPES = [
    'build',
    'chore',
    'ci',
    'docs',
    'feat',
    'fix',
    'perf',
    'refactor',
    'revert',
    'style',
    'test',
];

/**
 * Scope → package directory allow-list. An unlisted scope fails the gate, which is the point:
 * `chore(fix)` and a bare `Update symfony/yaml version constraint` both got through before it.
 *
 * `null` means the scope ships no package — its entries reach the aggregate changelog only.
 * Never infer the package from touched directories: `repo`, `website` and `ci` legitimately map
 * to nothing.
 */
const CONVENTIONAL_SCOPES = [
    'attributes' => 'php/attributes',
    'ci' => null,
    'core' => 'php/core',
    'inference-phpstan' => 'php/inference-phpstan',
    'laravel' => 'php/laravel',
    'repo' => null,
    'website' => null,
];

/** Composer package name per package scope, for the per-package changelog headings. */
const CONVENTIONAL_PACKAGE_NAMES = [
    'attributes' => 'docuccino/attributes',
    'core' => 'docuccino/core',
    'inference-phpstan' => 'docuccino/inference-phpstan',
    'laravel' => 'docuccino/laravel',
];

/**
 * Split a subject from the ` (#123)` GitHub appends when it squashes a pull request.
 *
 * @return array{0: string, 1: int|null}
 */
function conventional_split_reference(string $subject): array
{
    if (preg_match('/^(?<subject>.*?)\s*\(#(?<number>\d+)\)$/', trim($subject), $matches) === 1) {
        return [$matches['subject'], (int) $matches['number']];
    }

    return [trim($subject), null];
}

/**
 * Parse a subject line. Returns null when it is not a conventional subject at all; an unknown
 * type or scope still parses (the caller decides what to do about it).
 *
 * @return array{type: string, scope: string|null, breaking: bool, description: string, reference: int|null}|null
 */
function conventional_parse(string $subject): ?array
{
    [$bare, $reference] = conventional_split_reference($subject);

    $pattern = '/^(?<type>[a-zA-Z]+)(?:\((?<scope>[^()]*)\))?(?<bang>!?): (?<description>.+)$/';
    if (preg_match($pattern, $bare, $matches) !== 1) {
        return null;
    }

    return [
        'type' => $matches['type'],
        'scope' => ($matches['scope'] ?? '') === '' ? null : $matches['scope'],
        'breaking' => $matches['bang'] === '!',
        'description' => trim($matches['description']),
        'reference' => $reference,
    ];
}

/**
 * A merge commit, either by parent count (the reliable signal) or by git's own default subject
 * (all this file gets when a caller has no parent information).
 */
function conventional_is_merge(string $subject, int $parents = 1): bool
{
    return $parents > 1
        || preg_match('/^Merge (pull request|branch|remote-tracking branch|tag) /', trim($subject)) === 1;
}

/**
 * The standing release pull request's own squash subject, `Release v1.2.3`. Deliberately not a
 * conventional message — it lands nothing user-facing — so the changelog skips it in silence
 * instead of warning about it once per release, forever.
 */
function conventional_is_release_subject(string $subject): bool
{
    [$bare] = conventional_split_reference($subject);

    return preg_match('/^Release v\d+\.\d+\.\d+$/', $bare) === 1;
}

/**
 * The text of a `BREAKING CHANGE:` footer, `''` when the footer is present but empty, null when
 * there is no footer. Continuation lines fold into one paragraph.
 */
function conventional_breaking_footer(string $body): ?string
{
    $lines = preg_split('/\R/', $body) ?: [];
    $note = null;

    foreach ($lines as $line) {
        if ($note === null) {
            if (preg_match('/^BREAKING[ -]CHANGE:\s*(.*)$/', $line, $matches) === 1) {
                $note = trim($matches[1]);
            }

            continue;
        }

        if (trim($line) === '') {
            break;
        }

        $note = trim($note.' '.trim($line));
    }

    return $note;
}

/** The package directory a scope ships, or null for a scope (or a commit) with no package. */
function conventional_package(?string $scope): ?string
{
    if ($scope === null) {
        return null;
    }

    return CONVENTIONAL_SCOPES[$scope] ?? null;
}

/** Whether the scope is one the allow-list knows. */
function conventional_scope_allowed(string $scope): bool
{
    return array_key_exists($scope, CONVENTIONAL_SCOPES);
}

/** The allowed scopes as one readable list, for error messages. */
function conventional_scope_list(): string
{
    return implode(', ', array_keys(CONVENTIONAL_SCOPES));
}

/**
 * Everything wrong with a piece of author-written text this repository will publish or print.
 *
 * The changelog files are Markdown that a renderer passes raw HTML straight through, and the
 * generator prints to a terminal, so plain text is not what the destination reads. Two ways out of
 * it, both reproduced rather than imagined:
 *
 * - A control character. PCRE's `.` excludes `\n` and nothing else, while CommonMark counts a bare
 *   `\r` as a line ending, so `a fix\r## v9.9.9` forges a whole release section into a published
 *   changelog; `\x1b` drives the terminal the generator reports through.
 * - A `<` that opens a tag. `<script>` in the description reaches the docs site as a script.
 *
 * Deliberately allowed: everything Markdown renders as visible, inert text — emphasis, a link, an
 * entity — and anything at all inside a code span, which CommonMark binds tighter than raw HTML.
 * Unbackticked, `<` before a letter is refused even where no tag closes it: the renderer eats it
 * either way, so refusing it keeps the entry saying what it looks like.
 *
 * @return list<string>
 */
function conventional_text_problems(string $text, string $label): array
{
    $problems = [];

    // C0, DEL, and the UTF-8 spelling of C1. Byte ranges rather than `\p{Cc}/u`, which reports no
    // match at all on text that is not valid UTF-8 — the same answer it gives for clean text.
    if (preg_match('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', $text, $matches) === 1) {
        $problems[] = sprintf(
            '%s carries the control character %s — it must be one line of plain text, since a bare carriage return is a Markdown line ending and an escape drives the terminal.',
            $label,
            conventional_codepoint($matches[0]),
        );
    }

    if (preg_match('~<[A-Za-z!?/]~', conventional_without_code_spans($text), $matches) === 1) {
        $problems[] = sprintf(
            '%s opens raw HTML with `%s` — the changelog is Markdown that passes HTML through unescaped, so write it as words or wrap it in `backticks`.',
            $label,
            $matches[0],
        );
    }

    return $problems;
}

/**
 * `U+XXXX` for a control character, which is greppable where the character itself is invisible.
 *
 * @internal
 */
function conventional_codepoint(string $character): string
{
    return sprintf('U+%04X', ord(strlen($character) === 1 ? $character : $character[1]));
}

/**
 * The text with its code spans blanked out. CommonMark binds a code span tighter than raw HTML, so
 * `` `<script>` `` renders as the literal text it looks like and is not this gate's business.
 *
 * @internal
 */
function conventional_without_code_spans(string $text): string
{
    return preg_replace('/(`+)[\s\S]*?\1/', ' ', $text) ?? $text;
}

/**
 * Everything wrong with a title/body pair, as reader-facing sentences. Empty means valid.
 *
 * The two halves are cross-checked in both directions: a `!` without a `BREAKING CHANGE:` footer
 * (or a footer without the `!`) is how a breaking change lands as a routine entry.
 *
 * @return list<string>
 */
function conventional_title_problems(string $title, string $body = ''): array
{
    $subject = trim($title);
    if ($subject === '') {
        return ['the title is empty — it must read `type(scope): description`.'];
    }

    // Ahead of the grammar: a title carrying a `\r` parses perfectly well, and reporting it as
    // "no conventional type" would name the wrong thing to fix.
    $text = conventional_text_problems($subject, 'the title');
    if ($text !== []) {
        return $text;
    }

    $parsed = conventional_parse($subject);
    if ($parsed === null) {
        return [conventional_shape_problem($subject)];
    }

    $problems = [];

    if (! in_array($parsed['type'], CONVENTIONAL_TYPES, true)) {
        $problems[] = sprintf(
            'type `%s` is not a conventional type — use one of: %s.',
            $parsed['type'],
            implode(', ', CONVENTIONAL_TYPES),
        );
    }

    [$bare] = conventional_split_reference($subject);
    if (preg_match('/^[a-zA-Z]+\(\)/', $bare) === 1) {
        $problems[] = sprintf('the scope is empty — name one of: %s, or drop the brackets.', conventional_scope_list());
    } elseif ($parsed['scope'] !== null && ! conventional_scope_allowed($parsed['scope'])) {
        $problems[] = sprintf(
            'scope `%s` is not in the allow-list — use one of: %s (or omit the scope entirely).',
            $parsed['scope'],
            conventional_scope_list(),
        );
    }

    if (str_ends_with($parsed['description'], '.')) {
        $problems[] = 'the description must not end with a full stop.';
    }

    $footer = conventional_breaking_footer($body);

    if ($parsed['breaking'] && $footer === null) {
        $problems[] = 'the title is marked `!` but the body has no `BREAKING CHANGE:` footer describing what breaks.';
    }

    if (! $parsed['breaking'] && $footer !== null) {
        $problems[] = 'the body has a `BREAKING CHANGE:` footer but the title is not marked `!` — add it before the colon.';
    }

    if ($footer === '') {
        $problems[] = 'the `BREAKING CHANGE:` footer has no text — say what breaks and what to do instead.';
    }

    // The footer is the only part of a body the changelog publishes, so it is held to what the
    // title is held to. The rest of a body is never rendered anywhere and is left alone.
    if ($footer !== null && $footer !== '') {
        $problems = [...$problems, ...conventional_text_problems($footer, 'the `BREAKING CHANGE:` footer')];
    }

    return $problems;
}

/**
 * The one message for a subject that isn't conventional at all, with a pointed hint for the two
 * ways it actually goes wrong here.
 *
 * @internal
 */
function conventional_shape_problem(string $subject): string
{
    [$bare] = conventional_split_reference($subject);

    // Only claim a missing colon when the rest really does look like a conventional subject —
    // either it carries a `(scope)` or it opens with a known type. `Update symfony/yaml version
    // constraint` opens with neither and gets the general message instead.
    $looksTyped = preg_match('/^(?<type>[a-zA-Z]+)(?<scope>\([^()]*\))?!?\s+\S/', $bare, $head) === 1
        && (($head['scope'] ?? '') !== '' || in_array(strtolower($head['type']), CONVENTIONAL_TYPES, true));

    if ($looksTyped) {
        return sprintf(
            'the title is missing the colon after the type: `%s` should read `type(scope): description` — allowed scopes: %s.',
            $bare,
            conventional_scope_list(),
        );
    }

    return sprintf(
        'the title has no conventional type: `%s` should read `type(scope): description`, with a type from %s and a scope from %s.',
        $bare,
        implode(', ', CONVENTIONAL_TYPES),
        conventional_scope_list(),
    );
}

/**
 * True when this file's includer is the script PHP was invoked with, so a CLI tool can define its
 * functions for a test to require and still run when a human calls it.
 */
function conventional_is_entry_script(string $file): bool
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';

    return is_string($script) && $script !== '' && realpath($script) === realpath($file);
}
