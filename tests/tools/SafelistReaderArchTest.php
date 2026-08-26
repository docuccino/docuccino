<?php

declare(strict_types=1);

/*
 * One reader decides whether an `allow` entry matches.
 *
 * A pointer reaches a safelist spelled either bare or as a `#/…` URI fragment, and
 * `Docuccino\Core\Lint\LintSafelist` is the single place that reads both. A raw `in_array($subject,
 * $options->allow, true)` beside it is not a stricter reading, it is a config value that silently does
 * nothing in one half of the feature — which shipped: the lint honoured the fragment form while the
 * recorder's redaction did not, so one entry silenced a warning and still refused to publish the value
 * it was written for.
 *
 * So it is a thing to check rather than a thing to remember: every read of an `allow` list under a
 * package's `src/` either goes through that reader, or is named below with the reason it decides nothing.
 */

/**
 * The reads that compare no subject, each with what makes that true. A new entry is a claim about what
 * the list is being used FOR, so it needs the same kind of sentence.
 *
 * @return array<string, string>
 */
function safelistExplainedRawReads(): array
{
    return [
        // Carries the list across to a copy with extra heuristics merged in. Nothing is matched here,
        // and the copy's own reads are covered like any other.
        'php/core/src/Lint/SensitiveFieldLintOptions.php:57' => 'withPatterns() → copy forwarded to a new instance',
    ];
}

/**
 * The packages whose `src/` this scans.
 *
 * @return list<string>
 */
function safelistScannedPackages(): array
{
    return ['attributes', 'core', 'inference-phpstan', 'laravel'];
}

/**
 * Every `->allow` property read under the packages' sources, as `relative/path.php:LINE`, split by
 * whether `LintSafelist::matches()` owns the statement it sits in.
 *
 * @return array{directed: list<string>, raw: list<string>}
 */
function safelistPackageAllowReads(): array
{
    $reads = ['directed' => [], 'raw' => []];

    foreach (safelistScannedPackages() as $package) {
        foreach (safelistSourcesIn($package) as $relative => $source) {
            foreach (safelistAllowReadLines($source) as $kind => $lines) {
                foreach ($lines as $line) {
                    $reads[$kind][] = $relative.':'.$line;
                }
            }
        }
    }

    sort($reads['directed']);
    sort($reads['raw']);

    return $reads;
}

/**
 * The options classes carrying an `allow` list, as short class name => `relative/path.php`.
 *
 * @return array<string, string>
 */
function safelistPackageOptionsClasses(): array
{
    $classes = [];

    foreach (safelistScannedPackages() as $package) {
        foreach (safelistSourcesIn($package) as $relative => $source) {
            if (safelistDeclaresAllowList($source)) {
                $classes[basename($relative, '.php')] = $relative;
            }
        }
    }

    ksort($classes);

    return $classes;
}

/**
 * One package's PHP sources, as `relative/path.php` => contents.
 *
 * @return array<string, string>
 */
function safelistSourcesIn(string $package): array
{
    $root = dirname(__DIR__, 2);
    $directory = $root.'/php/'.$package.'/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    $sources = [];
    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $sources[str_replace($root.'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
    }

    return $sources;
}

/**
 * The line of every `->allow` read in one source, split into the ones `LintSafelist::matches()` owns and
 * the ones deciding for themselves.
 *
 * Tokenised rather than grepped, for the reason the other source scans here tokenise: it draws the
 * string-and-comment line for free. `->allow(…)` is a method of that name rather than the list, and the
 * statement is walked back to its own `;`/`{`/`}` so a call spread over several lines still reads as one.
 *
 * @return array{directed: list<int>, raw: list<int>}
 */
function safelistAllowReadLines(string $source): array
{
    $tokens = safelistSignificantTokens($source);
    $lines = ['directed' => [], 'raw' => []];

    foreach ($tokens as $i => $token) {
        if ($token->id !== T_STRING || $token->text !== 'allow') {
            continue;
        }

        $operator = $tokens[$i - 1]->id ?? null;
        if ($operator !== T_OBJECT_OPERATOR && $operator !== T_NULLSAFE_OBJECT_OPERATOR) {
            continue;
        }

        if (($tokens[$i + 1]->text ?? '') === '(') {
            continue;
        }

        $lines[safelistStatementNamesReader($tokens, $i) ? 'directed' : 'raw'][] = $token->line;
    }

    return $lines;
}

/**
 * Whether a `LintSafelist::matches(` opens the statement the read at $index sits in. A leading `\` and a
 * fully-qualified name are inert to PHP, so both are inert here.
 *
 * @param  list<PhpToken>  $tokens
 */
function safelistStatementNamesReader(array $tokens, int $index): bool
{
    for ($i = $index - 1; $i >= 0; $i--) {
        if (in_array($tokens[$i]->text, [';', '{', '}'], true)) {
            return false;
        }

        if ($tokens[$i]->id === T_STRING && $tokens[$i]->text === 'matches'
            && ($tokens[$i - 1]->id ?? null) === T_DOUBLE_COLON
            && str_ends_with($tokens[$i - 2]->text ?? '', 'LintSafelist')) {
            return true;
        }
    }

    return false;
}

/** Whether a source declares an `allow` list of its own — a property, promoted or not. */
function safelistDeclaresAllowList(string $source): bool
{
    $tokens = safelistSignificantTokens($source);

    foreach ($tokens as $i => $token) {
        if ($token->id !== T_VARIABLE || $token->text !== '$allow') {
            continue;
        }

        for ($j = $i - 1; $j >= max(0, $i - 3); $j--) {
            if (in_array($tokens[$j]->id, [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * The source's tokens with whitespace, comments and tags dropped, so a neighbour is the neighbour PHP
 * reads. Doc comments go with them, which is why a `@param … $allow` is never a declaration.
 *
 * @return list<PhpToken>
 */
function safelistSignificantTokens(string $source): array
{
    return array_values(array_filter(
        PhpToken::tokenize($source),
        static fn (PhpToken $token): bool => ! $token->isIgnorable(),
    ));
}

it('lets nothing but LintSafelist decide whether an allow entry matches', function (): void {
    $unexplained = array_values(array_diff(
        safelistPackageAllowReads()['raw'],
        array_keys(safelistExplainedRawReads()),
    ));

    expect($unexplained)->toBe([]);
});

/**
 * An explained read that has moved, or has been routed through the reader since, is a line that no longer
 * guards anything — and the next reader takes it for a statement about code that is still there.
 */
it('names no explained raw read that is not there any more', function (): void {
    $stale = array_values(array_diff(
        array_keys(safelistExplainedRawReads()),
        safelistPackageAllowReads()['raw'],
    ));

    expect($stale)->toBe([]);
});

/**
 * The half a per-read scan cannot see: a list nothing reads through the reader at all. Every options
 * class carrying one has to be named by some file that does, its own included.
 */
it('reads every options class allow list through the one reader', function (): void {
    $root = dirname(__DIR__, 2);
    $directedFiles = array_unique(array_map(
        static fn (string $read): string => (string) strstr($read, ':', true),
        safelistPackageAllowReads()['directed'],
    ));

    $unread = [];
    foreach (safelistPackageOptionsClasses() as $class => $path) {
        $named = array_filter(
            $directedFiles,
            static fn (string $file): bool => str_contains((string) file_get_contents($root.'/'.$file), $class),
        );

        if ($named === []) {
            $unread[] = $class.' ('.$path.')';
        }
    }

    expect($unread)->toBe([]);
});

/**
 * A scan that matches nothing passes forever. These are the counts the assertions above are worth: both
 * options classes are still found, the reads are still found, and the reader they go through is still
 * the one that knows about the two spellings.
 */
it('is scanning something', function (): void {
    $safelist = dirname(__DIR__, 2).'/php/core/src/Lint/LintSafelist.php';

    expect(array_keys(safelistPackageOptionsClasses()))
        ->toContain('LintRuleOptions')
        ->toContain('SensitiveFieldLintOptions')
        ->and(count(safelistPackageAllowReads()['directed']))->toBeGreaterThanOrEqual(3)
        ->and(safelistExplainedRawReads())->not->toBeEmpty()
        ->and(file_get_contents($safelist))->toContain("str_starts_with(\$subject, '#/')");
});

/**
 * The scanner's own proof. Both spellings that reach the reader, and the shapes a `->allow` grep would
 * get wrong in either direction: a read written in a comment, a method sharing the name, a local
 * variable sharing it, and a call to the reader spread over more than one line.
 */
it('sees a read the reader owns apart from one deciding for itself', function (): void {
    $source = <<<'PHP'
        <?php

        final readonly class Options
        {
            public function __construct(
                public array $allow = [],
                private array $patterns = [],
            ) {}

            public function silences(string $subject): bool
            {
                return LintSafelist::matches($this->allow, $subject);
            }

            public function alsoSilences(string $subject): bool
            {
                return \Docuccino\Core\Lint\LintSafelist::matches(
                    $this->allow,
                    $subject,
                );
            }

            public function decidesAlone(string $subject): bool
            {
                // LintSafelist::matches($this->allow, $subject) in a comment is not a call.
                return in_array($subject, $this->allow, true);
            }

            public function names(): array
            {
                return $this->allow;
            }

            public function allow(): array
            {
                $allow = $this->patterns;

                return $allow;
            }

            public function copy(): array
            {
                return $this->allow();
            }
        }
        PHP;

    expect(safelistAllowReadLines($source))->toBe(['directed' => [12, 18], 'raw' => [26, 31]])
        // A promoted property is a declaration; the local `$allow` on line 36 is not — a visibility
        // keyword is what makes one, which is also why the counter-source below declares nothing.
        ->and(safelistDeclaresAllowList($source))->toBeTrue()
        ->and(safelistDeclaresAllowList("<?php\n\$allow = [];\n"))->toBeFalse();
});
