<?php

declare(strict_types=1);

/*
 * The scanner behind the diagnostics reference guard: every diagnostic code the packages can emit,
 * read out of their sources, with the severities each is emitted at.
 *
 * It tokenises rather than greps because a guard must read the same grammar as the thing it guards.
 * A `code:` regex sees named arguments and nothing else, and the engine passes its codes
 * positionally; a code-shaped literal anywhere in a file, at the other extreme, also picks up config
 * keys. So this reads argument LISTS, which is the shape a diagnostic is actually written in.
 *
 * What it can see:
 *   - `new Diagnostic(severity: Severity::Warning, code: 'route.x', …)` — named arguments.
 *   - `new Diagnostic(Severity::Warning, 'inference.x', …)` — positional, as the engine writes them.
 *   - `$this->report($context, Severity::Warning, 'example-file.missing', …)` — a private helper that
 *     forwards to the constructor, recorded at the CALL, where the code is still a literal.
 *   - `code: self::CODE` and codes held in a `const` table — resolved from the constants declared in
 *     the same file, which is the only place the sites that do this keep them.
 *   - a severity chosen by a ternary (`$kept ? Severity::Info : Severity::Warning`), recorded as both.
 *
 * What it cannot see: a code assembled by concatenation, and one held in another file's constant.
 * Neither shape exists today, and {@see diagnostic_code_sites()} reports the construction sites whose
 * code argument is not a literal so the guard can assert the residue stays where it is understood.
 */

/** A diagnostic code: dot-separated lowercase segments, hyphens inside a segment. */
const DIAGNOSTIC_CODE_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*(\.[a-z0-9]+(-[a-z0-9]+)*)+$/';

/**
 * Every code the given source directories emit, as `code => sorted list of severities`.
 *
 * @param  list<string>  $directories
 * @return array<string, list<string>>
 */
function diagnostic_codes(array $directories): array
{
    $codes = [];

    foreach (diagnostic_source_files($directories) as $file) {
        foreach (diagnostic_codes_in_source((string) file_get_contents($file)) as $code => $severities) {
            $codes[$code] = array_values(array_unique(array_merge($codes[$code] ?? [], $severities)));
        }
    }

    foreach ($codes as $code => $severities) {
        sort($severities);
        $codes[$code] = $severities;
    }

    ksort($codes);

    return $codes;
}

/**
 * The files holding a `Diagnostic` construction whose code argument is not a literal, package-relative.
 *
 * Each is a place the scan falls back to the file's constants, so the guard can say out loud which
 * files it is trusting to keep their codes where they can be found. Files rather than lines: the
 * guard pins this list, and a line number moves every time somebody edits above it.
 *
 * @param  list<string>  $directories
 * @return list<string>
 */
function diagnostic_code_sites(array $directories): array
{
    $sites = [];

    foreach (diagnostic_source_files($directories) as $file) {
        if (diagnostic_has_indirect_code((string) file_get_contents($file))) {
            $sites[] = diagnostic_relative_path($file, $directories);
        }
    }

    sort($sites);

    return $sites;
}

/**
 * Every `.php` file under the given directories, in a stable order.
 *
 * @param  list<string>  $directories
 * @return list<string>
 */
function diagnostic_source_files(array $directories): array
{
    $files = [];

    foreach ($directories as $directory) {
        $found = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($found as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * The codes one PHP source emits, as `code => list of severities` in encounter order.
 *
 * @return array<string, list<string>>
 */
function diagnostic_codes_in_source(string $source): array
{
    $tokens = diagnostic_tokens($source);
    $codes = [];

    foreach (diagnostic_argument_lists($tokens) as $list) {
        $arguments = diagnostic_split_arguments($tokens, $list['start'], $list['end']);
        $severities = [];
        foreach ($arguments as $argument) {
            $severities = array_merge($severities, diagnostic_severities($tokens, $argument));
        }

        if ($severities === []) {
            continue;
        }

        if (! $list['diagnostic']) {
            // A helper that forwards to the constructor. Its shape is unknown, so any lone code-shaped
            // literal beside a severity counts — messages and help text never match the code grammar.
            foreach ($arguments as $argument) {
                $literal = diagnostic_literal($tokens, $argument);

                if ($literal !== null && preg_match(DIAGNOSTIC_CODE_PATTERN, $literal) === 1) {
                    $codes[$literal] = array_merge($codes[$literal] ?? [], $severities);
                }
            }

            continue;
        }

        $code = diagnostic_named_argument($tokens, $arguments, 'code', 1);
        $literal = $code === null ? null : diagnostic_literal($tokens, $code);

        if ($literal !== null) {
            $codes[$literal] = array_merge($codes[$literal] ?? [], $severities);

            continue;
        }

        // The residue: a site holding its code somewhere else, resolved from its own file's constants.
        foreach ($code === null ? [] : diagnostic_indirect_codes($tokens, $code) as $named) {
            $codes[$named] = array_merge($codes[$named] ?? [], $severities);
        }
    }

    return $codes;
}

/** Whether one source constructs a `Diagnostic` whose code argument is not a literal. */
function diagnostic_has_indirect_code(string $source): bool
{
    $tokens = diagnostic_tokens($source);

    foreach (diagnostic_argument_lists($tokens) as $list) {
        if (! $list['diagnostic']) {
            continue;
        }

        $arguments = diagnostic_split_arguments($tokens, $list['start'], $list['end']);
        $code = diagnostic_named_argument($tokens, $arguments, 'code', 1);

        if ($code === null || diagnostic_literal($tokens, $code) === null) {
            return true;
        }
    }

    return false;
}

/**
 * The source's tokens with whitespace and comments dropped, renumbered from zero.
 *
 * @return list<array{0: int, 1: string, 2: int}|string>
 */
function diagnostic_tokens(string $source): array
{
    $tokens = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $tokens[] = $token;
    }

    return $tokens;
}

/**
 * Every argument list in the source: where it starts and ends, and whether the callee is a
 * `Diagnostic` constructor.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return list<array{start: int, end: int, diagnostic: bool}>
 */
function diagnostic_argument_lists(array $tokens): array
{
    $count = count($tokens);
    $lists = [];

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i] !== '(') {
            continue;
        }

        $previous = $tokens[$i - 1] ?? null;

        if (! is_array($previous) || ! in_array($previous[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        $name = ltrim((string) $previous[1], '\\');
        $segments = explode('\\', $name);
        $new = $tokens[$i - 2] ?? null;

        $lists[] = [
            'start' => $i + 1,
            'end' => diagnostic_matching_paren($tokens, $i),
            'diagnostic' => end($segments) === 'Diagnostic'
                && is_array($new) && $new[0] === T_NEW,
        ];
    }

    return $lists;
}

/**
 * The index of the `)` closing the `(` at $open.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 */
function diagnostic_matching_paren(array $tokens, int $open): int
{
    $count = count($tokens);
    $depth = 0;

    for ($i = $open; $i < $count; $i++) {
        if ($tokens[$i] === '(') {
            $depth++;
        } elseif ($tokens[$i] === ')') {
            $depth--;

            if ($depth === 0) {
                return $i;
            }
        }
    }

    return $count;
}

/**
 * One argument list split on its top-level commas, as `[start, end]` index pairs.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return list<array{0: int, 1: int}>
 */
function diagnostic_split_arguments(array $tokens, int $start, int $end): array
{
    $arguments = [];
    $depth = 0;
    $from = $start;

    for ($i = $start; $i < $end; $i++) {
        $token = $tokens[$i];

        if (in_array($token, ['(', '[', '{'], true)) {
            $depth++;
        } elseif (in_array($token, [')', ']', '}'], true)) {
            $depth--;
        } elseif ($token === ',' && $depth === 0) {
            if ($i > $from) {
                $arguments[] = [$from, $i];
            }

            $from = $i + 1;
        }
    }

    if ($end > $from) {
        $arguments[] = [$from, $end];
    }

    return $arguments;
}

/**
 * The argument passed as $name, or the one at $position when the call is positional — as the index
 * pair of its VALUE, with any `name:` label stripped.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @param  list<array{0: int, 1: int}>  $arguments
 * @return array{0: int, 1: int}|null
 */
function diagnostic_named_argument(array $tokens, array $arguments, string $name, int $position): ?array
{
    $positional = [];

    foreach ($arguments as [$start, $end]) {
        $label = diagnostic_argument_label($tokens, $start, $end);

        if ($label === null) {
            $positional[] = [$start, $end];

            continue;
        }

        if ($label === $name) {
            return [$start + 2, $end];
        }
    }

    return $positional[$position] ?? null;
}

/**
 * The `name:` label an argument opens with, if it has one.
 *
 * A named argument is a bare name followed by a single `:`. `Severity::Info` is a name followed by
 * `::`, which tokenises as T_DOUBLE_COLON and is therefore never mistaken for a label.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 */
function diagnostic_argument_label(array $tokens, int $start, int $end): ?string
{
    $first = $tokens[$start] ?? null;

    if ($end - $start < 2 || ! is_array($first) || $first[0] !== T_STRING || ($tokens[$start + 1] ?? null) !== ':') {
        return null;
    }

    return $first[1];
}

/**
 * The value of an argument that is exactly one single-quoted string, or null for anything else.
 *
 * Single-quoted only: a double-quoted string can interpolate, and no code is written that way.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @param  array{0: int, 1: int}  $argument
 */
function diagnostic_literal(array $tokens, array $argument): ?string
{
    [$start, $end] = $argument;
    $token = $tokens[$start] ?? null;

    if ($end - $start !== 1 || ! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
        return null;
    }

    if (! str_starts_with($token[1], "'")) {
        return null;
    }

    return str_replace(['\\\\', "\\'"], ['\\', "'"], substr($token[1], 1, -1));
}

/**
 * The `Severity::Case` names an argument mentions — more than one where a ternary picks between them.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @param  array{0: int, 1: int}  $argument
 * @return list<string>
 */
function diagnostic_severities(array $tokens, array $argument): array
{
    [$start, $end] = $argument;
    $severities = [];

    for ($i = $start; $i < $end - 1; $i++) {
        $token = $tokens[$i];
        $case = $tokens[$i + 2] ?? null;

        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'Severity') {
            continue;
        }

        if (($tokens[$i + 1] ?? null) === null || ! is_array($tokens[$i + 1]) || $tokens[$i + 1][0] !== T_DOUBLE_COLON) {
            continue;
        }

        if (is_array($case) && $case[0] === T_STRING) {
            $severities[] = strtolower($case[1]);
        }
    }

    return $severities;
}

/**
 * What an indirect `code:` argument can resolve to, out of the constants of its own file.
 *
 * `self::CODE` resolves to the constant it names. A plain `$variable` is always a code read out of a
 * table, so it resolves to every code-shaped literal the file's `const` ARRAYS hold — which leaves a
 * scalar constant holding a config key out of it. Anything else, `$diagnostic->code` included,
 * resolves to nothing: that one forwards a code minted elsewhere rather than adding one.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @param  array{0: int, 1: int}  $argument
 * @return list<string>
 */
function diagnostic_indirect_codes(array $tokens, array $argument): array
{
    [$start, $end] = $argument;
    $first = $tokens[$start] ?? null;

    if ($end - $start === 1 && is_array($first) && $first[0] === T_VARIABLE) {
        return diagnostic_constant_codes($tokens, null);
    }

    $name = $tokens[$start + 2] ?? null;

    if ($end - $start !== 3 || ! is_array($tokens[$start + 1]) || $tokens[$start + 1][0] !== T_DOUBLE_COLON) {
        return [];
    }

    return is_array($name) ? diagnostic_constant_codes($tokens, $name[1]) : [];
}

/**
 * The code-shaped literals the source declares as class constants — those of the constant $name, or,
 * where $name is null, those of every constant declared as an array.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return list<string>
 */
function diagnostic_constant_codes(array $tokens, ?string $name): array
{
    $count = count($tokens);
    $codes = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token) || $token[0] !== T_CONST) {
            continue;
        }

        // `const NAME = …` or `const array NAME = …`: the name is the token before the `=`.
        $assign = $i;
        while ($assign < $count && $tokens[$assign] !== '=' && $tokens[$assign] !== ';') {
            $assign++;
        }

        $declared = $tokens[$assign - 1] ?? null;
        $value = $tokens[$assign + 1] ?? null;
        $wanted = $name === null
            ? ($value === '[' || (is_array($value) && $value[0] === T_ARRAY))
            : (is_array($declared) && $declared[1] === $name);

        for ($i = $assign; $i < $count && $tokens[$i] !== ';'; $i++) {
            $literal = $wanted ? diagnostic_literal($tokens, [$i, $i + 1]) : null;

            if ($literal !== null && preg_match(DIAGNOSTIC_CODE_PATTERN, $literal) === 1) {
                $codes[] = $literal;
            }
        }
    }

    return $codes;
}

/**
 * A source path shortened to the package-relative form the guard reports.
 *
 * @param  list<string>  $directories
 */
function diagnostic_relative_path(string $file, array $directories): string
{
    foreach ($directories as $directory) {
        $prefix = rtrim($directory, '/').'/';

        if (str_starts_with($file, $prefix)) {
            return substr($file, strlen($prefix));
        }
    }

    return $file;
}

/**
 * The codes one Markdown reference page documents, as `code => sorted list of severities`.
 *
 * A row is `| `code` | severity | … |` — the code in the first cell as inline code, the severities it
 * is emitted at named in the second ("info or warning" is two). The first cell has to hold a code,
 * dot and all, so the page's other tables — severities, formats — are read as the prose they are.
 *
 * @return array<string, list<string>>
 */
function diagnostic_documented_codes(string $markdown): array
{
    $documented = [];

    foreach (explode("\n", $markdown) as $line) {
        $line = trim($line);

        if (! str_starts_with($line, '|')) {
            continue;
        }

        $cells = array_map(trim(...), array_slice(explode('|', $line), 1, -1));

        if (count($cells) < 2 || preg_match('/^`([a-z0-9-]+(\.[a-z0-9-]+)+)`$/', $cells[0], $matches) !== 1) {
            continue;
        }

        preg_match_all('/\b(error|warning|info|hint)\b/', strtolower($cells[1]), $severities);
        $found = array_values(array_unique($severities[1]));
        sort($found);

        $documented[$matches[1]] = $found;
    }

    ksort($documented);

    return $documented;
}
