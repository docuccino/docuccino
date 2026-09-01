<?php

declare(strict_types=1);

/*
 * One reader decides whether a config entry names a subject.
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
 *
 * `selectors` — the operations an `#[AppliesTo]` declares — is scanned the same way and for the
 * same reason. It is the identical shape of problem: entries the author writes, subjects the build
 * knows by more than one name, and a wildcard. A matcher of its own would have been a second grammar
 * before it was a second bug.
 *
 * Its reader is a different one: `Docuccino\Core\Support\Glob`, which is the product's one wildcard
 * grammar. The safelists deliberately do NOT go through it — they are controls rather than
 * conveniences, and an entry there matches exactly what it spells — so each property names the reader
 * that owns it and the scan asks for that one.
 */

/**
 * The reader each scanned property must go through, as `property => [class, method]`.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function safelistReaders(): array
{
    return [
        'allow' => ['LintSafelist', 'matches'],
        'selectors' => ['Glob', 'matchesAny'],
    ];
}

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
        'php/core/src/Lint/SensitiveFieldLintOptions.php::withPatterns' => 'copy forwarded to a new instance',
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
 * The reads of an `#[AppliesTo]` selector list that compare no subject. Same rule, same kind of sentence.
 *
 * @return array<string, string>
 */
function safelistExplainedRawSelectorReads(): array
{
    return [
        // Whether the change is scoped at all, which chooses between two whole paths and matches nothing.
        'php/laravel/src/Versioning/ApiVersionTransformer.php::transform' => 'an unscoped change takes the other branch',
        // Hands each selector to the reader on its own, so the one that decided nothing can be named.
        'php/laravel/src/Versioning/ApiVersionTransformer.php::applyScoped' => 'iterates the selectors into namesAny()',
    ];
}

/**
 * Every `->allow` property read under the packages' sources, as `relative/path.php:LINE`, split by
 * whether `LintSafelist::matches()` owns the statement it sits in.
 *
 * @return array{directed: list<string>, raw: list<string>}
 */
function safelistPackageAllowReads(string $property = 'allow'): array
{
    $reads = ['directed' => [], 'raw' => []];

    foreach (safelistScannedPackages() as $package) {
        foreach (safelistSourcesIn($package) as $relative => $source) {
            foreach (safelistAllowReadSites($source, $property) as $kind => $sites) {
                foreach ($sites as $site) {
                    $reads[$kind][] = $relative.'::'.$site;
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
function safelistAllowReadSites(string $source, string $property = 'allow'): array
{
    $tokens = safelistSignificantTokens($source);
    [$readerClass, $readerMethod] = safelistReaders()[$property];
    $sites = ['directed' => [], 'raw' => []];

    foreach ($tokens as $i => $token) {
        if ($token->id !== T_STRING || $token->text !== $property) {
            continue;
        }

        $operator = $tokens[$i - 1]->id ?? null;
        if ($operator !== T_OBJECT_OPERATOR && $operator !== T_NULLSAFE_OBJECT_OPERATOR) {
            continue;
        }

        if (($tokens[$i + 1]->text ?? '') === '(') {
            continue;
        }

        $sites[safelistStatementNamesReader($tokens, $i, $readerClass, $readerMethod) ? 'directed' : 'raw'][] = enclosingFunction($tokens, $i);
    }

    return $sites;
}

/**
 * Whether a `<Reader>::<method>(` opens the statement the read at $index sits in. A leading `\` and a
 * fully-qualified name are inert to PHP, so both are inert here.
 *
 * @param  list<PhpToken>  $tokens
 */
function safelistStatementNamesReader(array $tokens, int $index, string $class = 'LintSafelist', string $method = 'matches'): bool
{
    for ($i = $index - 1; $i >= 0; $i--) {
        if (in_array($tokens[$i]->text, [';', '{', '}'], true)) {
            return false;
        }

        if ($tokens[$i]->id === T_STRING && $tokens[$i]->text === $method
            && ($tokens[$i - 1]->id ?? null) === T_DOUBLE_COLON
            && str_ends_with($tokens[$i - 2]->text ?? '', $class)) {
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

it('lets nothing but LintSafelist decide whether an entry matches', function (string $property, array $explained): void {
    $unexplained = array_values(array_diff(
        safelistPackageAllowReads($property)['raw'],
        array_keys($explained),
    ));

    expect($unexplained)->toBe([]);
})->with([
    'a lint allow list' => ['allow', safelistExplainedRawReads()],
    'a version change scope' => ['selectors', safelistExplainedRawSelectorReads()],
]);

/**
 * An explained read that has moved, or has been routed through the reader since, is a line that no longer
 * guards anything — and the next reader takes it for a statement about code that is still there.
 */
it('names no explained raw read that is not there any more', function (string $property, array $explained): void {
    $stale = array_values(array_diff(
        array_keys($explained),
        safelistPackageAllowReads($property)['raw'],
    ));

    expect($stale)->toBe([]);
})->with([
    'a lint allow list' => ['allow', safelistExplainedRawReads()],
    'a version change scope' => ['selectors', safelistExplainedRawSelectorReads()],
]);

/**
 * Keying an explained read on its function rather than its line is what stops the list rotting on
 * drift — but it means a SECOND raw read in an already-explained function collides with the entry
 * standing for the first, and the per-key diff above cannot see it. So the counts are held to each
 * other as well: one explained entry accounts for exactly one raw read.
 */
it('explains one raw read per entry, so a second in the same function cannot hide behind it', function (string $property, array $explained): void {
    expect(count(safelistPackageAllowReads($property)['raw']))->toBe(count($explained));
})->with([
    'a lint allow list' => ['allow', safelistExplainedRawReads()],
    'a version change scope' => ['selectors', safelistExplainedRawSelectorReads()],
]);

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
        // The scope half: a selector still reaches the reader, and the raw reads beside it are still
        // there to be explained. Zero of either would pass its own assertion forever.
        ->and(count(safelistPackageAllowReads('selectors')['directed']))->toBeGreaterThanOrEqual(1)
        ->and(count(safelistPackageAllowReads('selectors')['raw']))->toBeGreaterThanOrEqual(2)
        ->and(safelistExplainedRawSelectorReads())->not->toBeEmpty()
        ->and(file_get_contents($safelist))->toContain("str_starts_with(\$subject, '#/')")
        // And the safelist reader really is EXACT. Its two callers are controls — one silences a
        // leakage finding, the other un-redacts a value credential matching flagged — so a wildcard
        // reader here would widen what an already-written config entry accepts. The wildcard grammar
        // belongs to the readers that document one, which is what the `selectors` half scans for.
        ->and(file_get_contents($safelist))->toContain('in_array(self::canonical($subject), $entries, true)')
        ->and(file_get_contents($safelist))->not->toContain('Glob::');
});

/**
 * The scanner's own proof, and the guard EXECUTED rather than claimed. Both spellings that reach a
 * reader, and the shapes a `->allow` grep would get wrong in either direction: a read written in a
 * comment, a method sharing the name, a local variable sharing it, and a call spread over more than one
 * line. Then the bespoke matcher each property's scan must refuse — including, for `selectors`, the
 * OTHER property's reader, which is the mistake a shared scan would let through.
 */
it('sees a read the reader owns apart from one deciding for itself', function (): void {
    $source = static fn (string $reader, string $qualified, string $method): string => <<<PHP
        <?php

        final readonly class Options
        {
            public function __construct(
                public array \$allow = [],
                private array \$patterns = [],
            ) {}

            public function silences(string \$subject): bool
            {
                return {$reader}::{$method}(\$this->allow, \$subject);
            }

            public function alsoSilences(string \$subject): bool
            {
                return {$qualified}::{$method}(
                    \$this->allow,
                    \$subject,
                );
            }

            public function decidesAlone(string \$subject): bool
            {
                // {$reader}::{$method}(\$this->allow, \$subject) in a comment is not a call.
                return in_array(\$subject, \$this->allow, true);
            }

            public function names(): array
            {
                return \$this->allow;
            }

            public function allow(): array
            {
                \$allow = \$this->patterns;

                return \$allow;
            }

            public function copy(): array
            {
                return \$this->allow();
            }
        }
        PHP;

    $lint = $source('LintSafelist', '\Docuccino\Core\Lint\LintSafelist', 'matches');
    $glob = str_replace('allow', 'selectors', $source('Glob', '\Docuccino\Core\Support\Glob', 'matchesAny'));

    expect(safelistAllowReadSites($lint))->toBe(['directed' => ['silences', 'alsoSilences'], 'raw' => ['decidesAlone', 'names']])
        // A promoted property is a declaration; the local `$allow` inside allow() is not — a visibility
        // keyword is what makes one, which is also why the counter-source below declares nothing.
        ->and(safelistDeclaresAllowList($lint))->toBeTrue()
        ->and(safelistDeclaresAllowList("<?php\n\$allow = [];\n"))->toBeFalse();

    // The same scan under the other property name and the other reader. The parameter is what makes one
    // scan two, so a change that quietly hard-coded either name again shows up here.
    expect(safelistAllowReadSites($glob, 'selectors'))->toBe(['directed' => ['silences', 'alsoSilences'], 'raw' => ['decidesAlone', 'names']])
        ->and(safelistAllowReadSites($lint, 'selectors'))->toBe(['directed' => [], 'raw' => []])
        ->and(safelistAllowReadSites($glob))->toBe(['directed' => [], 'raw' => []]);

    // And the refusal, written out: each property's reader is its OWN. A selector read through
    // `LintSafelist::matches` — the call this stack shipped, and the one that widened a redaction
    // control to accept `*` — is raw here, not directed, so the guard would name it.
    $crossed = str_replace('allow', 'selectors', $lint);

    expect(safelistAllowReadSites($crossed, 'selectors'))->toBe(['directed' => [], 'raw' => ['silences', 'alsoSilences', 'decidesAlone', 'names']]);
});
