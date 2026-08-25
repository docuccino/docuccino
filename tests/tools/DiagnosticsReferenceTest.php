<?php

declare(strict_types=1);
use Docuccino\Core\Diagnostics\DiagnosticDocs;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Support\ConfinedPath;

require_once dirname(__DIR__, 2).'/tools/diagnostic-codes.php';

/*
 * The guard that keeps the diagnostics reference honest: every code the packages can emit has a row
 * on the page, at the severity it is actually emitted at, and the page names no code that no longer
 * exists. A reference nothing checks is stale within a month, and a reader who is told a code does
 * not exist stops trusting the page rather than the code.
 *
 * The scan's limits are written down in tools/diagnostic-codes.php. The short version: it reads
 * argument LISTS, so it sees a code written beside its severity — named or positional, at the
 * constructor or at a helper that forwards to it — and resolves the rest from the constants of the
 * file that holds them. A code assembled by concatenation, or kept in another file's constant, would
 * be invisible; the last test here pins the sites that need the constant fallback, so a new one is a
 * decision somebody makes rather than a row that quietly goes missing.
 */
/** @return list<string> */
function diagnosticSourceDirectories(): array
{
    $root = dirname(__DIR__, 2);

    return [$root.'/php/core/src', $root.'/php/laravel/src', $root.'/php/inference-phpstan/src'];
}

function diagnosticReferencePage(): string
{
    return (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/reference/diagnostics.md',
    );
}

/**
 * Every test the packages carry, as one string. Only the package suites — the guards under
 * `tests/tools/` read the code list itself, so counting them would let a code satisfy this by being
 * documented.
 */
function diagnosticTestCorpus(): string
{
    $root = dirname(__DIR__, 2);
    $corpus = '';
    $files = 0;

    foreach (['core', 'laravel', 'inference-phpstan'] as $package) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root.'/php/'.$package.'/tests',
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile()) {
                $corpus .= file_get_contents($entry->getPathname());
                $files++;
            }
        }
    }

    // A corpus that stopped being read would make every code look unprovoked, which fails loudly — but
    // one that shrank to a handful of files would fail confusingly, so the size is pinned too.
    expect($files)->toBeGreaterThan(400);

    return $corpus;
}

/**
 * Codes no test names, and why. The reason has to be structural, and the guard checks the structure:
 * an excused code must be raised ONLY by the analyser package, whose real paths run against the
 * fixture app rather than in-process — so nothing in core or the adapter can ever be excused here.
 *
 * @return array<string, string>
 */
function diagnosticUnprovokedCodes(): array
{
    return [
        'inference.callable-failed' => 'PhpStanTypeEngine::analyzeCallable is total against a real PHPStan container; FileAnalyzer is final, so there is no seam to make one throw without booting the fixture app.',
        'inference.callable-not-found' => 'Same seam: it needs a real container to hand back a callable with no analysable body.',
    ];
}

it('names every code the packages emit in at least one test', function (): void {
    // DiagnosticsReferenceTest proves every code is DOCUMENTED; nothing proved any was ever reached.
    // Three Postman codes could have had their whole branch deleted with the suite green. This is a
    // reference scan, not a provocation trace — a test that names a code may only be constructing one —
    // but a code no test names has certainly never been seen raised.
    $emitted = diagnostic_codes(diagnosticSourceDirectories());
    $corpus = diagnosticTestCorpus();
    $excused = diagnosticUnprovokedCodes();

    $unnamed = [];
    foreach (array_keys($emitted) as $code) {
        if (! str_contains($corpus, $code) && ! isset($excused[$code])) {
            $unnamed[] = $code;
        }
    }

    expect($unnamed)->toBe([], 'codes no test names')
        ->and(count($emitted))->toBeGreaterThan(100);
});

it('excuses only codes the analyser package alone can raise', function (): void {
    $root = dirname(__DIR__, 2);
    $engine = diagnostic_codes([$root.'/php/inference-phpstan/src']);
    $inProcess = diagnostic_codes([$root.'/php/core/src', $root.'/php/laravel/src']);

    $wrong = [];
    foreach (diagnosticUnprovokedCodes() as $code => $reason) {
        if (! isset($engine[$code])) {
            $wrong[] = $code.': not raised by the analyser package';
        }
        if (isset($inProcess[$code])) {
            $wrong[] = $code.': raised in-process too, so a test can reach it';
        }
        if (trim($reason) === '') {
            $wrong[] = $code.': excused without saying why';
        }
    }

    expect($wrong)->toBe([])
        ->and(count($engine))->toBeGreaterThan(5)
        ->and(count($inProcess))->toBeGreaterThan(100);
});

it('documents every code the packages emit, and no code they do not', function (): void {
    $emitted = diagnostic_codes(diagnosticSourceDirectories());
    $documented = diagnostic_documented_codes(diagnosticReferencePage());

    expect(array_keys(array_diff_key($emitted, $documented)))->toBe([], 'codes with no row on the reference page')
        ->and(array_keys(array_diff_key($documented, $emitted)))->toBe([], 'rows for codes nothing emits');
});

it('gives every code the severity it is emitted at', function (): void {
    $emitted = diagnostic_codes(diagnosticSourceDirectories());
    $documented = diagnostic_documented_codes(diagnosticReferencePage());

    $wrong = [];
    foreach ($emitted as $code => $severities) {
        if (($documented[$code] ?? $severities) !== $severities) {
            $wrong[] = $code.': emitted '.implode('/', $severities).', page says '.implode('/', $documented[$code]);
        }
    }

    expect($wrong)->toBe([]);
});

/*
 * The reference is not the only page that tables diagnostics: a guide covering one family repeats that
 * family's rows so a reader following the guide need not leave it. A second copy nothing reads is the
 * first one going stale — the narrative-content guide kept the wording of an escape refusal that its
 * canonical row had been corrected out of, and nothing could have said so. Each row here names the page
 * and the code prefix it owns; adding a family table to a guide is a line here.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function diagnosticFamilyPages(): array
{
    return [
        'narrative content' => ['laravel/guides/narrative-content.mdx', 'content.'],
        // The recorded-examples page tables the same family, and had no row here: a code added to the
        // family reached the reference and left the guide one short, which is the drift this list exists
        // to catch and not a second kind of problem.
        'recorded examples' => ['laravel/documenting/examples.mdx', 'examples.'],
    ];
}

it('holds a guide that repeats a diagnostic family to the family the packages emit', function (string $page, string $prefix): void {
    $emitted = diagnostic_codes(diagnosticSourceDirectories());
    $documented = diagnostic_documented_codes((string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/'.$page,
    ));

    $family = static fn (array $codes): array => array_filter(
        $codes,
        static fn (string $code): bool => str_starts_with($code, $prefix),
        ARRAY_FILTER_USE_KEY,
    );

    $emitted = $family($emitted);
    $documented = $family($documented);

    $wrong = [];
    foreach ($emitted as $code => $severities) {
        if (($documented[$code] ?? $severities) !== $severities) {
            $wrong[] = $code.': emitted '.implode('/', $severities).', page says '.implode('/', $documented[$code]);
        }
    }

    expect(array_keys(array_diff_key($emitted, $documented)))->toBe([], 'codes the guide\'s table is short of')
        ->and(array_keys(array_diff_key($documented, $emitted)))->toBe([], 'rows for codes nothing emits')
        ->and($wrong)->toBe([])
        // Anti-vacuity: a prefix that matched nothing would agree with an empty table.
        ->and(count($emitted))->toBeGreaterThanOrEqual(5);
})->with(diagnosticFamilyPages());

/*
 * A scan that silently finds nothing passes forever. These are the floors: well under what the
 * packages emit today, so ordinary work never trips them, and far enough above zero that a scanner
 * that stopped seeing one of the shapes below fails loudly instead of going quiet.
 */
it('finds a plausible number of codes across a plausible number of families', function (): void {
    $codes = diagnostic_codes(diagnosticSourceDirectories());
    $families = array_unique(array_map(
        static fn (string $code): string => explode('.', $code)[0],
        array_keys($codes),
    ));

    expect(count($codes))->toBeGreaterThan(100)
        ->and(count($families))->toBeGreaterThan(20);
});

it('sees a code written in each of the shapes the packages use', function (string $code, string $severity): void {
    expect(diagnostic_codes(diagnosticSourceDirectories()))->toHaveKey($code)
        ->and(diagnostic_codes(diagnosticSourceDirectories())[$code])->toBe([$severity]);
})->with([
    // A named argument at the constructor — how most of the packages write one.
    'named argument' => ['route.operation-collision', 'error'],
    // Positional, as the engine writes them.
    'positional argument' => ['inference.action-failed', 'warning'],
    // Passed to a private helper that forwards to the constructor.
    'helper call' => ['attribute.example-unusable', 'warning'],
    // Held in a class constant the constructor names.
    'class constant' => ['config.machine-dependent-value', 'warning'],
    // Held in a constant table the emitter loops over.
    'constant table' => ['downlevel.tag-parent', 'warning'],
]);

it('records both severities where one is chosen at emit time', function (): void {
    expect(diagnostic_codes(diagnosticSourceDirectories())['downlevel.multi-type'])->toBe(['info', 'warning']);
});

it('reads a code out of every shape, and nothing out of the shapes that are not codes', function (): void {
    $codes = diagnostic_codes_in_source(<<<'PHP'
        <?php

        final class Emitter
        {
            private const string CODE = 'demo.from-constant';

            private const array TABLE = [
                'summary' => ['demo.from-table', 'Some help text, which is not a code.'],
            ];

            // Not a code: a config key, in a call that carries no severity.
            private const string KEY = 'invoices.export.path';

            public function run(): array
            {
                $out = [];
                $out[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'demo.named',
                    message: sprintf('Something about %s.', 'invoices'),
                );
                $out[] = new Diagnostic(Severity::Error, 'demo.positional', 'Something else.');
                $out[] = new Diagnostic(
                    severity: $folded ? Severity::Info : Severity::Warning,
                    code: 'demo.either-way',
                    message: 'A message.',
                );
                $out[] = new \Docuccino\Core\Diagnostics\Diagnostic(
                    severity: Severity::Hint,
                    code: 'demo.fully-qualified',
                    message: 'A message.',
                );
                $this->report(Severity::Info, 'demo.via-helper', 'A message.');
                $out[] = new Diagnostic(severity: Severity::Warning, code: self::CODE, message: 'A message.');

                foreach (self::TABLE as [$code, $help]) {
                    $out[] = new Diagnostic(severity: Severity::Warning, code: $code, message: 'A message.');
                }

                // Neither of these carries a severity, so neither is a diagnostic.
                $this->config(self::KEY);
                $this->translate('invoices.overdue');

                return $out;
            }
        }
        PHP);

    expect($codes)->toHaveKeys([
        'demo.named',
        'demo.positional',
        'demo.either-way',
        'demo.fully-qualified',
        'demo.via-helper',
        'demo.from-constant',
        'demo.from-table',
    ])
        ->and($codes)->not->toHaveKey('invoices.export.path')
        ->and($codes)->not->toHaveKey('invoices.overdue')
        ->and($codes['demo.positional'])->toBe(['error'])
        ->and($codes['demo.either-way'])->toBe(['info', 'warning'])
        ->and($codes['demo.fully-qualified'])->toBe(['hint']);
});

it('reads no code out of a source that emits none', function (): void {
    expect(diagnostic_codes_in_source("<?php\n\nfinal class Plain { public const string KEY = 'invoices.export.path'; }\n"))
        ->toBe([]);
});

it('reads the codes and severities a reference page publishes, and only those', function (): void {
    $documented = diagnostic_documented_codes(<<<'MARKDOWN'
        ## Severities

        | Severity | What it means |
        |---|---|
        | **Error** | Something is missing. |

        ## Codes

        | Code | Severity | What it means | What to do |
        |---|---|---|---|
        | `demo.named` | warning | It happened. | Fix it. |
        | `demo.either-way` | info or warning | It happened. | Nothing. |

        Prose about `demo.named` in a paragraph is not a row.
        MARKDOWN);

    expect($documented)->toBe([
        'demo.either-way' => ['info', 'warning'],
        'demo.named' => ['warning'],
    ]);
});

/*
 * The residue. Each of these constructs a Diagnostic whose code argument is not a literal, so the
 * scan falls back to the constants of the file it sits in — except DocumentGenerator, which forwards
 * a code minted elsewhere and therefore contributes none of its own. Adding a site to this list is
 * fine; doing it without noticing is not, because a code that reaches one of them from another file
 * would be documented by nobody.
 */
it('names the construction sites whose code is not written beside it', function (): void {
    expect(diagnostic_code_sites(diagnosticSourceDirectories()))->toBe([
        'Emit/OpenApi31DownlevelEmitter.php',
        'Extensions/BuiltIn/AttributeExamplesExtension.php',
        'Extensions/BuiltIn/AttributeOverridesExtension.php',
        'Pipeline/DocumentGenerator.php',
        'Support/MachineDependentValue.php',
    ]);
});

/**
 * Every `.php` file the packages ship, as path => contents.
 *
 * @return array<string, string>
 */
function diagnosticSourceContents(): array
{
    $out = [];

    foreach (diagnosticSourceDirectories() as $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $path = (string) $file;
            $out[$path] = (string) file_get_contents($path);
        }
    }

    ksort($out);

    return $out;
}

/**
 * The longest leading run of a sentence that no PHP quoting style would spell differently — cut at the
 * first character a single- or double-quoted literal could escape. That is what makes a search for a
 * hand-typed copy independent of how the copy was quoted.
 */
function diagnosticHelpFragment(string $sentence): string
{
    $cut = strcspn($sentence, '\'"\\$');

    return substr($sentence, 0, $cut);
}

/*
 * One owner per help sentence. Each of these restates a rule some other code enforces, and each has
 * already reached its reporters as separate literals — the component-key rule as three copies (core's
 * shared-error-response naming, the adapter's `#[ErrorComponent]` reader, the inferred-handler
 * integration's), the two `file:` refusals as four, in the example reader and the description reader,
 * while the constants that own them had no readers at all. These two rows are what keep them drawn
 * from one place: the first refuses a site that types the words out again (a copy is free to drift
 * from the rule it describes), the second refuses a reporter of a code that states the rule some other
 * way instead. Both are derived, not listed: the words come from the constant and the files come from
 * the codes, so neither goes stale on a reword or a rename.
 */
it('states a rule a diagnostic restates in one file only', function (string $sentence, string $owner): void {
    $fragment = diagnosticHelpFragment($sentence);

    expect(strlen($fragment))->toBeGreaterThan(20);

    $holders = [];
    foreach (diagnosticSourceContents() as $path => $contents) {
        if (str_contains($contents, $fragment)) {
            $holders[] = basename($path);
        }
    }

    expect($holders)->toBe([$owner]);
})->with([
    [ComponentNames::LEGAL_NAME_HELP, 'ComponentNames.php'],
    [ConfinedPath::FILE_ESCAPED_HELP, 'ConfinedPath.php'],
    [ConfinedPath::FILE_MISSING_HELP, 'ConfinedPath.php'],
    // The config-facing half of the same two refusals. Same rule, same owner, a different place for the
    // author to go and edit — so it is the same drift risk and owes the same row.
    [ConfinedPath::CONFIG_FILE_ESCAPED_HELP, 'ConfinedPath.php'],
    [ConfinedPath::CONFIG_FILE_MISSING_HELP, 'ConfinedPath.php'],
]);

/**
 * References written the way the source writes them — `Owner::CONSTANT` — rather than bare constant
 * names. A bare name is a SUBSTRING of the config-facing one (`FILE_ESCAPED_HELP` sits inside
 * `CONFIG_FILE_ESCAPED_HELP`), so a reporter drawing on either would satisfy a row naming the other and
 * a reporter drawing on NEITHER would have to be caught by something else.
 *
 * @param  list<string>  $constants
 */
function diagnosticDrawsOnAny(string $contents, array $constants): bool
{
    foreach ($constants as $constant) {
        if (str_contains($contents, $constant)) {
            return true;
        }
    }

    return false;
}

it('reports each of those with the rule its owner states', function (array $constants, array $codes, int $floor): void {
    $reporters = [];
    $restating = [];
    foreach (diagnosticSourceContents() as $path => $contents) {
        $raises = false;
        foreach ($codes as $code) {
            $raises = $raises || str_contains($contents, "'".$code."'");
        }

        if (! $raises) {
            continue;
        }

        $reporters[] = basename($path);

        if (! diagnosticDrawsOnAny($contents, $constants)) {
            $restating[] = basename($path);
        }
    }

    // Anti-vacuity: a scan that stopped finding the reporters would pass the assertion beside this
    // while proving nothing, so the count each row has today is asserted too. Each floor EQUALS the
    // count on the tree, which is what makes it worth having: a reporter deleted — or a scan that
    // stopped seeing one — drops below it, while a new one passes on `>=`. A floor set one below the
    // real count buys nothing and hides exactly the deletion it was put there to catch.
    expect($restating)->toBe([])
        ->and(count($reporters))->toBeGreaterThanOrEqual($floor);
})->with([
    // `ComponentRegistry::LEGAL_NAME_HELP` is an alias of the owner's constant, not a copy — it is how
    // an integration, which may only import the public surface, draws on the same sentence.
    [['ComponentNames::LEGAL_NAME_HELP', 'ComponentRegistry::LEGAL_NAME_HELP'], ['components.name-invalid', 'attribute.error-component-invalid'], 4],
    // Two codes with reporters on both sides of the attribute/config line, so either constant of the
    // pair satisfies a row: an attribute reader owes the `file:` wording and a config reader the
    // configured-path wording, and both are drawn from the one owner.
    [
        ['ConfinedPath::FILE_ESCAPED_HELP', 'ConfinedPath::CONFIG_FILE_ESCAPED_HELP'],
        // A configured DIRECTORY refused for leaving the application is the same refusal with the same
        // remedy, so the recordings audit draws on the same configured-path sentence rather than its own.
        ['example-file.escapes-base-path', 'description-file.escapes-base-path', 'examples.recordings-escapes-base'],
        4,
    ],
    [
        ['ConfinedPath::FILE_MISSING_HELP', 'ConfinedPath::CONFIG_FILE_MISSING_HELP'],
        ['example-file.missing', 'description-file.missing'],
        3,
    ],
]);

/*
 * The links a build prints. A link to an anchor that is not there is worse than no link: it tells the
 * reader the page has moved on without them. These hold the map in DiagnosticDocs to the page it
 * points at, in both directions.
 */

/** The section anchors the page really publishes, slugified the way a heading becomes an id. */
function diagnosticReferenceAnchors(): array
{
    $anchors = [];

    foreach (explode("\n", diagnosticReferencePage()) as $line) {
        if (preg_match('/^## (.+)$/', trim($line), $m) !== 1) {
            continue;
        }

        $slug = strtolower($m[1]);
        $slug = preg_replace('/[^a-z0-9 -]/', '', $slug) ?? '';
        $anchors[] = trim(preg_replace('/\s+/', '-', trim($slug)) ?? '', '-');
    }

    return $anchors;
}

it('points every emitted code at an anchor the page has', function (): void {
    $anchors = diagnosticReferenceAnchors();
    $codes = array_keys(diagnostic_codes(diagnosticSourceDirectories()));

    // Anti-vacuity: a scan that found no codes, or a page with no sections, would pass every
    // assertion below while proving nothing.
    expect($codes)->not->toBeEmpty()
        ->and($anchors)->not->toBeEmpty();

    $missing = [];
    foreach ($codes as $code) {
        $url = DiagnosticDocs::urlFor($code);

        if (! str_contains($url, '#')) {
            $missing[] = $code.' (no section mapped)';

            continue;
        }

        $anchor = substr($url, strpos($url, '#') + 1);

        if (! in_array($anchor, $anchors, true)) {
            $missing[] = $code.' -> #'.$anchor;
        }
    }

    expect($missing)->toBe([]);
});

it('maps no prefix the page cannot answer for', function (): void {
    $anchors = diagnosticReferenceAnchors();
    $prefixes = DiagnosticDocs::prefixes();

    expect($prefixes)->not->toBeEmpty();

    $dead = [];
    foreach ($prefixes as $prefix) {
        $anchor = substr(DiagnosticDocs::urlFor($prefix.'.probe'), strpos(DiagnosticDocs::urlFor($prefix.'.probe'), '#') + 1);

        if (! in_array($anchor, $anchors, true)) {
            $dead[] = $prefix.' -> #'.$anchor;
        }
    }

    expect($dead)->toBe([]);
});

/*
 * The HOST as well as the anchor. The anchor test above proves the fragment names a section that
 * exists; it says nothing about which site the link points AT, and a link to the wrong host is
 * broken in a way no page-source check can see. The docs site's own canonical URL is the one
 * `website/astro.config.mjs` builds against, so read it from there rather than repeating it.
 */
it('points at the site the docs are actually published to', function (): void {
    $config = (string) file_get_contents(dirname(__DIR__, 2).'/website/astro.config.mjs');

    expect(preg_match("/site:\s*'([^']+)'/", $config, $m))->toBe(1);

    $site = rtrim($m[1], '/');
    $url = DiagnosticDocs::urlFor('engine.not-installed');

    expect($site)->not->toBeEmpty()
        ->and($url)->toStartWith($site.'/');

    // And the CNAME the deploy actually serves from, which is what a reader's browser resolves.
    $cname = trim((string) file_get_contents(dirname(__DIR__, 2).'/website/public/CNAME'));

    expect($cname)->not->toBeEmpty()
        ->and($url)->toStartWith('https://'.$cname.'/');
});
