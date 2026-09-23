<?php

declare(strict_types=1);

use Docuccino\Core\Emit\Formats;

/*
 * A format id is a NAME, not a family.
 *
 * Three readers once asked "is this a plain OpenAPI artifact?" by writing
 * `str_starts_with($id, 'openapi-')`, and the proxy held only while the ids happened to agree with
 * it. Naming the full artifact `openapi-3.2-full` made all three answer yes for the one artifact
 * that is not plain — one of them `DocumentEmitOptions::openApiBeside()`, which decides what a
 * published Arazzo description points consumers at, so the wrong answer was a provenance disclosure
 * and not a cosmetic one. Nothing failed: a prefix test is deterministic, it was simply wrong.
 *
 * The id shipped is `full`, so that collision is gone at the SOURCE rather than guarded against:
 * `full` is not in the `openapi-` family and no prefix test can read it as one. What is left is the
 * class, which is what this refuses — a prefix or substring test whose literal is a strict prefix of
 * any id the table knows AND at least four characters long, `'postm'` and `'arazz'` as much as
 * `'openapi-'`. The ids agree with `'openapi-'` again today, and agreeing is the condition the
 * original proxy was written under and went on satisfying right up to the rename that broke it; a
 * prefix test does not fail when it stops being true, it answers differently. So: a question about a
 * format is a column on `Formats::TABLE` — `serialisesYaml()`, `checksEmittedArtifact()`,
 * `publishesPlainOpenApi()` — or a new column, and never the spelling of the id. Comparing against a
 * whole id is fine; that is using the name for what it is.
 *
 * **What it does NOT cover is stated below rather than implied.** The four-character floor means an
 * id of four characters or fewer has no catchable literal at all, and `full` is one of six rows in
 * that position. A guard whose docblock claims the whole table and covers five sixths of it is worse
 * than one that names its gap, so the reach is carried per id, measured, in a row apiece.
 */

/** Every directory whose sources may not test a format id by its shape. */
function formatIdScanRoots(): array
{
    $root = dirname(__DIR__, 2);
    $roots = [];

    foreach (['attributes', 'core', 'inference-phpstan', 'laravel'] as $package) {
        $roots[] = $root.'/php/'.$package.'/src';
        $roots[] = $root.'/php/'.$package.'/tests';
    }

    // The workbench app and the repo's own tooling tests: two of the three original readers were
    // test-side, so a scan that stopped at `src/` would have caught one of them.
    $roots[] = $root.'/php/laravel/workbench';
    $roots[] = $root.'/tests/tools';

    return $roots;
}

/** Every prefix- or substring-matching call under those roots, paired with each literal it matches on. */
function scannedPrefixMatchCalls(): array
{
    $found = [];

    foreach (formatIdScanRoots() as $directory) {
        foreach (phpSourcesIn($directory, dirname(__DIR__, 2)) as $relative => $path) {
            foreach (prefixMatchCalls((string) file_get_contents($path)) as $call) {
                $found[] = $relative.'::'.$call['site'].' → '.$call['literal'];
            }
        }
    }

    sort($found);

    return $found;
}

/** Every one of those calls that is testing a format id by its shape. */
function scannedFormatIdPrefixTests(): array
{
    $found = [];

    foreach (formatIdScanRoots() as $directory) {
        $found = [...$found, ...formatIdPrefixTestsIn($directory, Formats::ids(), dirname(__DIR__, 2))];
    }

    sort($found);

    return $found;
}

it('lets nothing decide what a format is by the shape of its id', function (): void {
    expect(scannedFormatIdPrefixTests())->toBe(
        [],
        'a question about a format is a column on Formats::TABLE, never a test on the spelling of its id',
    );
});

/**
 * A scan that matches nothing passes forever, and this one's real assertion is an empty set — so the
 * only thing standing between it and permanent silence is the denominator. Both halves are counted: the
 * ids it is scanning FOR, and the matching calls it is scanning THROUGH.
 */
it('is scanning something, and scanning for the ids the table actually has', function (): void {
    $calls = scannedPrefixMatchCalls();

    expect(Formats::ids())->not->toBeEmpty()
        ->and(count(Formats::ids()))->toBeGreaterThanOrEqual(4)
        ->and(formatIdScanRoots())->toHaveCount(10)
        // A floor well under the measured count, so ordinary churn does not move it and a scanner that
        // stopped seeing calls does.
        ->and(count($calls))->toBeGreaterThanOrEqual(150, 'the prefix-match scan found nothing, so the empty result above proves nothing');
});

/**
 * The boundary, pinned rather than assumed. `OpenApiMetaSchema` prefix-matches too, and it is right to:
 * what it matches is a vendored meta-schema's `$id` URL, which is not a format id and answers a
 * different question (which JSON Schema dialect the file is written in). The scan has to SEE those
 * calls and still not flag them, so both halves are asserted — a scanner blind to them would report the
 * same clean result for the wrong reason.
 */
it('reads the meta-schema’s own $id prefix tests as calls, and not as format-id tests', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/php/core/src/SpecValidation/OpenApiMetaSchema.php');

    $urls = array_values(array_filter(
        prefixMatchCalls($source),
        static fn (array $call): bool => str_starts_with($call['literal'], 'https://spec.openapis.org/oas/'),
    ));

    expect(count($urls))->toBeGreaterThanOrEqual(2, 'the meta-schema no longer prefix-matches its $id, so this boundary has moved')
        ->and(formatIdPrefixTests($source, Formats::ids()))->toBe([]);
});

/**
 * The scanner's own proof, executed rather than asserted: every shape it must refuse, and the ones it
 * must not. The near misses matter more than the hits here — a guard that flagged `'openapi:'` or
 * `'document.openapi-invalid'` would be turned off within a week, and the whole class with it.
 */
it('sees a format id tested by its shape, and only that', function (): void {
    $source = <<<'PHP'
        <?php

        final class Sneaky
        {
            public const string ADVICE = 'never write str_starts_with($id, "openapi-") here';

            /** Nor in a docblock: str_contains($id, 'openapi-3.'). */
            public function plain(string $id): bool
            {
                return str_starts_with($id, 'openapi-');
            }

            public function regex(string $id): bool
            {
                return preg_match('/^openapi-/', $id) === 1;
            }

            public function family(string $id): bool
            {
                return \str_contains($id, 'postm') || str_ends_with($id, 'arazz');
            }

            public function notAFormatTest(string $id, string $yaml, string $code): array
            {
                // A whole id compared against its name — `===` is not a matcher call at all, so
                // this proves the scan's reach and not the whole-id exemption, which is exercised
                // where it DECIDES two tests below. Then: a YAML key; a diagnostic code that merely
                // contains the word; the extension member every emitter walks; and a literal too
                // short to be anybody's format.
                return [
                    $id === 'full',
                    str_contains($yaml, 'openapi:'),
                    str_starts_with($code, 'document.openapi-invalid'),
                    str_starts_with($id, 'x-'),
                    str_starts_with($id, 'ope'),
                ];
            }

            public function notThisFunction(string $id): bool
            {
                return $this->str_starts_with($id, 'openapi-') || Other::str_contains($id, 'openapi-');
            }

            private function str_starts_with(string $id, string $needle): bool
            {
                return false;
            }
        }
        PHP;

    $ids = ['openapi-3.2', 'openapi-3.1', 'openapi-3.0', 'full', 'postman', 'arazzo'];

    expect(array_column(formatIdPrefixTests($source, $ids), 'line'))->toBe([10, 15, 20, 20])
        ->and(array_column(formatIdPrefixTests($source, $ids), 'site'))->toBe(['plain', 'regex', 'family', 'family']);
});

/**
 * What the guard can refuse, per id — including the rows it cannot refuse anything on.
 *
 * The scan's real assertion is an empty set over the whole table, which reads as "every id is
 * covered". It is not: {@see formatIdPrefixTests} floors the literal at four characters, so an id
 * that short has no strict prefix it could ever flag. `full` is that id, and a docblock claiming the
 * table while one row in six is unreachable is the kind of overstatement that stops the next reader
 * writing the guard they needed. So every id carries a row, derived by RUNNING the predicate rather
 * than by reasoning about it, and a row of zero says so out loud.
 *
 * *Could the floor come down instead?* Measured across the 673 matcher-call literals under
 * {@see formatIdScanRoots}: a floor of 3 or 2 flags nothing extra, and a floor of 1 flags one false
 * positive — a literal `'a'`, a strict prefix of `arazzo`. So the floor could drop at no measured
 * cost, and it would buy `'ful'` and `'fu'`: literals nobody writes. The shape a reader reaching for
 * a family question about `full` actually writes is `str_contains($id, 'full')`, a WHOLE id, which
 * the exemption permits and which is equivalent to `$id === 'full'` for as long as `full` is the only
 * id carrying that word. Flagging a whole id inside a substring matcher would close that — and would
 * put this guard's diagnostic on `ProvenanceLevel::Full`, which shares the word and nothing else,
 * for zero firings measured today. A four-character id is not prefix-attackable, and this row says
 * so rather than implying it is covered.
 */
it('says, per id, what a shape test could be read off it', function (): void {
    $reach = [];

    foreach (Formats::ids() as $id) {
        $catchable = 0;

        for ($length = 1; $length < strlen($id); $length++) {
            // The predicate itself, run on a source carrying that one literal — not a re-statement of
            // its rule, which would agree with whatever the rule happens to be.
            $source = '<?php $x = str_starts_with($id, '.var_export(substr($id, 0, $length), true).');';

            $catchable += formatIdPrefixTests($source, Formats::ids()) === [] ? 0 : 1;
        }

        $reach[$id] = $catchable;
    }

    // Derived from the table, so an id added without a row of its own fails here rather than joining
    // `full` in the silence.
    expect($reach)->toBe([
        'openapi-3.2' => 7,
        'openapi-3.1' => 7,
        'openapi-3.0' => 7,
        // The row that owes no answer, saying so. Four characters: every strict prefix is under the
        // floor. See the docblock for why lowering it does not help.
        'full' => 0,
        'postman' => 3,
        'arazzo' => 2,
    ]);
});

/**
 * The whole-id exemption, executed where it DECIDES.
 *
 * Comparing against a whole id is correct, so the predicate exempts it. Against today's table that
 * exemption changes no outcome — no id is a strict prefix of another, so a whole-id literal falls out
 * of the loop unflagged with or without it — and an exemption nothing can reach is a claim rather
 * than a rule. The table that motivated this whole guard HAD one (`openapi-3.2` inside
 * `openapi-3.2-full`) and the next one may again, so the predicate is run against a table that does:
 * `openapi-3.2` is there both as a name and as a strict prefix of another name, and only the
 * exemption keeps it unflagged.
 */
it('reads a whole id as a name even where it is also a strict prefix of another id', function (): void {
    $ids = ['openapi-3.2', 'openapi-3.2-full', 'full'];

    $name = '<?php $x = str_starts_with($id, \'openapi-3.2\');';
    $prefix = '<?php $x = str_starts_with($id, \'openapi-3.\');';

    expect(formatIdPrefixTests($name, $ids))->toBe([], 'a whole id compared against its own name is not a shape test')
        // And the boundary beside it: one character shorter is nobody's name, and is flagged.
        ->and(array_column(formatIdPrefixTests($prefix, $ids), 'literal'))->toBe(['openapi-3.']);
});
