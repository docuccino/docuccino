<?php

declare(strict_types=1);

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
