<?php

declare(strict_types=1);

use Docuccino\Core\Emit\Formats;
use Docuccino\Laravel\Commands\ExportCommand;

/*
 * The guard behind the export-format tabs. The page used to open on "five other artifacts on request",
 * a count nobody checked that was wrong twice over — it counted the formats wrong and counted `--yaml`,
 * a serialisation, among them. Formats is the source of truth for both halves.
 */

function firstExportPage(): string
{
    return (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/getting-started/first-export.mdx',
    );
}

/**
 * The YAML tab's warning paragraph: the prose saying which targets refuse a `.yaml` path, and why.
 *
 * Bounded by the tab it lives in and then by its own blank lines, never by a byte count. A
 * 600-character window from the tab's label overran the tab and swallowed the sibling tabs, whose
 * bodies each demonstrate a `--format=<id>` command — so every id the assertion looked for matched
 * whether the warning was there or not, and deleting the whole warning left the guard green.
 */
function yamlRefusalParagraph(string $page): string
{
    $open = strpos($page, 'YAML (a serialization)');

    if ($open === false) {
        return '';
    }

    $close = strpos($page, '</TabItem>', $open);
    $tab = substr($page, $open, $close === false ? null : $close - $open);

    foreach (preg_split('/\R\s*\R/', $tab) ?: [] as $paragraph) {
        if (str_contains($paragraph, 'read JSON and nothing else')) {
            return $paragraph;
        }
    }

    return '';
}

/** The format ids the page's own tabs demonstrate, read off the commands in them. */
function demonstratedFormatIds(string $page): array
{
    preg_match_all('/--format=([\w.-]+)/', $page, $matches);

    $ids = array_values(array_unique($matches[1]));
    sort($ids);

    return $ids;
}

it('knows a plausible set of formats, and only real ones', function (): void {
    // A Formats table that stopped being readable would make every assertion below vacuous.
    $ids = Formats::ids();

    expect(count($ids))->toBeGreaterThanOrEqual(4)
        ->and($ids)->toContain(Formats::DEFAULT, 'full', 'postman')
        ->and(array_filter($ids, static fn (string $id): bool => ! Formats::supports($id)))->toBe([]);
});

it('demonstrates every format the exporter supports except the default', function (): void {
    // The default needs no --format, so it is the one id the tabs show without naming.
    $expected = array_values(array_filter(
        Formats::ids(),
        static fn (string $id): bool => $id !== Formats::DEFAULT,
    ));
    sort($expected);

    expect(demonstratedFormatIds(firstExportPage()))->toBe($expected)
        ->and(firstExportPage())->toContain('OpenAPI 3.2 by default');
});

it('states no count of the artifacts, since a count is a promise to remember', function (): void {
    // The exact defect this guard exists for: prose that carries the number rather than deriving it.
    $intro = substr(firstExportPage(), (int) strpos(firstExportPage(), '## Other formats and paths'), 400);

    expect($intro)->not->toContain('five other')
        ->and($intro)->not->toMatch('/\b(two|three|four|five|six|seven) (other )?(artifacts|formats)\b/i');
});

it('calls `--yaml` a serialization rather than a format, and says which targets take it', function (): void {
    $page = firstExportPage();

    // It is validated against Formats::serialisesYaml(), never against the format list, so a page that
    // files it beside --format is telling the reader to expect a --format=yaml that does not exist.
    expect(Formats::supports('yaml'))->toBeFalse()
        ->and($page)->toContain('`--yaml` is a *serialization*');

    $yamlFormats = array_values(array_filter(Formats::ids(), Formats::serialisesYaml(...)));
    $jsonOnly = array_values(array_filter(
        Formats::ids(),
        static fn (string $id): bool => ! Formats::serialisesYaml($id),
    ));

    // Anti-vacuity: the claim below is only worth checking while both sets are non-empty.
    expect($yamlFormats)->not->toBeEmpty()
        ->and($jsonOnly)->not->toBeEmpty();

    $paragraph = yamlRefusalParagraph($page);

    // Anti-vacuity, and the defect that made it necessary: the paragraph has to be FOUND before an
    // assertion over its contents means anything.
    expect($paragraph)->not->toBe('', 'the YAML tab no longer explains why a `.yaml` path is refused');

    $unmentioned = array_values(array_filter(
        $jsonOnly,
        // A case-sensitive CODE SPAN, never a bare substring: `full` is four common letters and
        // `postman` is the label of the tab two below this one.
        static fn (string $id): bool => ! str_contains($paragraph, '`'.$id.'`'),
    ));

    expect($unmentioned)->toBe([], 'formats with no YAML serialisation that the tab never warns about');
});

/*
 * And the other places a format id is written by hand: the commands a package PRINTS, and the export
 * command's own signature. `docuccino:coverage` tells a reader which export to run and
 * `ChangeScaffolder` does the same — literals no test compared with the table, so a renamed format
 * left them naming a command that errors. The signature is the hand-maintained full set of the ids,
 * and `artisan help` is where most readers meet it, so it owes a guard that reads the table.
 *
 * Scoped to `docuccino:export`, because `docuccino:diff` has a `--format` of its own whose values
 * (`json`, `terminal`) are nothing to do with the emitter table.
 *
 * Both spellings count. `--format=<id>` and `--format <id>` are the same instruction, and a scan that
 * read only the first passed a page telling a reader to run a format that does not exist.
 */

/** Every `docuccino:export --format <id>` written into a package's own source, by file. */
function printedExportFormats(): array
{
    $found = [];

    foreach (['attributes', 'core', 'inference-phpstan', 'laravel'] as $package) {
        $dir = dirname(__DIR__, 2).'/php/'.$package.'/src';

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                '/docuccino:export[^\r\n]{0,80}?--format[= ]([\w.-]*\w)/',
                (string) file_get_contents($file->getPathname()),
                $matches,
            );

            foreach ($matches[1] as $id) {
                $found[] = $file->getFilename().': '.$id;
            }
        }
    }

    sort($found);

    return array_values(array_unique($found));
}

it('never tells a reader to run an export format the table does not know', function (): void {
    $printed = printedExportFormats();

    // Anti-vacuity: a scanner that stopped seeing the shape would otherwise pass forever. Two of these
    // are the coverage report's hint and the change scaffolder's, and both named `uir` until it moved.
    expect(count($printed))->toBeGreaterThanOrEqual(2, 'the --format scan found nothing, so it proves nothing');

    $unknown = array_values(array_filter($printed, static function (string $row): bool {
        [, $id] = explode(': ', $row, 2);

        return ! Formats::supports($id);
    }));

    expect($unknown)->toBe([], 'printed --format values naming a format the emitter table has not got');
});

it('spells every format the table knows in the export signature, and invents none', function (): void {
    $signature = (string) ((new ReflectionClass(ExportCommand::class))
        ->getDefaultProperties()['signature'] ?? '');

    expect($signature)->not->toBe('');

    preg_match('/\{--format= : ([^—]+)—/', $signature, $matches);

    expect($matches)->not->toBeEmpty('the --format option no longer lists its values');

    $listed = array_values(array_filter(array_map(trim(...), explode('|', $matches[1]))));
    sort($listed);

    $expected = Formats::ids();
    sort($expected);

    expect($listed)->toBe($expected, 'the signature and the emitter table disagree about which formats exist');
});

/*
 * And the third population, which is the largest: every format id hand-written into a page. Only the
 * export-tabs page was governed, while a dozen further literals sat in the commands reference, the
 * diagnostics reference, four guides, the home page and the upgrade notes — all correct, and none of
 * them checked, which is the same missed sweep every time a format is renamed.
 *
 * Three shapes are deliberately out of scope, each for a reason rather than by file:
 *
 * - **Signature lines.** The commands reference reproduces each `$signature` verbatim, and
 *   `CommandsReferenceTest` already holds every one of those lines to the PHP source.
 * - **Another command's `--format`.** The option is not export's alone — `docuccino:diff` carries its
 *   own `terminal | json` — so a line that names other commands and never names export is naming one
 *   of theirs, and the emitter table has no say over it. Scoping this by the command rather than by
 *   the signature line is the point: `docuccino:diff --format=json` is as legitimate in a guide as it
 *   is in a signature, and a guard that recognised only the signature would call the guide wrong.
 * - **A retired id named with its replacement.** The upgrade notes must be able to write
 *   `--format=uir`; what they may not do is write it alone. {@see Formats::replacementHint()} knows
 *   which ids this version retired, so the rule is checkable: name the old id and the new one on the
 *   same line, or do not name the old one.
 *
 * The space form is scoped to an invocation, unlike the `=` form: `--format=<id>` can only be naming a
 * format, where "the `--format` flag" is a sentence, and prose about the option is not a claim that a
 * format called "flag" exists.
 */

/** Every Markdown file the site and the repository publish, as an absolute path. */
function documentationPages(): array
{
    $root = dirname(__DIR__, 2);

    $paths = [$root.'/UPGRADING.md', $root.'/README.md'];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/website/src/content/docs'),
    );

    foreach ($files as $file) {
        if (in_array($file->getExtension(), ['md', 'mdx'], true)) {
            $paths[] = $file->getPathname();
        }
    }

    sort($paths);

    return $paths;
}

/**
 * Every `--format` a page text names, as [line number, id, the whole line] — the line travels with the
 * id so the predicate below can read the sentence it was written in, and so a failure says where.
 *
 * @return list<array{int, string, string}>
 */
function formatIdsNamedIn(string $page): array
{
    $found = [];

    foreach (preg_split('/\r?\n/', $page) ?: [] as $number => $line) {
        // A signature line, held to the PHP source by CommandsReferenceTest rather than here.
        if (preg_match('/^\s*\{-/', $line) === 1) {
            continue;
        }

        // Another command's option: `--format` belongs to `docuccino:diff` as well, so a line naming
        // commands and never naming export is naming one of theirs. A line naming export, or naming no
        // command at all, is read as an export claim.
        preg_match_all('/docuccino:[a-z-]+/', $line, $commands);
        if ($commands[0] !== [] && ! in_array('docuccino:export', $commands[0], true)) {
            continue;
        }

        preg_match_all('/--format(=|\s)([\w.-]*\w)/', $line, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if ($match[1] !== '=' && ! str_contains($line, 'docuccino:export')) {
                continue;
            }

            $found[] = [$number + 1, $match[2], $line];
        }
    }

    return $found;
}

/**
 * The same, across every page, keyed by where — "<file>:<line>".
 *
 * @return list<array{string, string, string}>
 */
function documentedExportFormats(): array
{
    $found = [];

    foreach (documentationPages() as $path) {
        foreach (formatIdsNamedIn((string) file_get_contents($path)) as [$number, $id, $line]) {
            $found[] = [basename($path).':'.$number, $id, $line];
        }
    }

    return $found;
}

/**
 * Whether a documented format id is sound: one the emitter table knows, or one this version retired
 * written beside what replaced it. Pure — it reads the line rather than the file — so the refusal can
 * be executed on a line written to fail it.
 */
function documentedFormatIsSound(string $id, string $line): bool
{
    if (Formats::supports($id)) {
        return true;
    }

    // `replacementHint` is the message a user meets on upgrade, and it is the only public statement of
    // which ids were retired — so the rule reads it rather than keeping a second list.
    //
    // The replacement has to appear as an ID, in the shape the scan above reads ids in, NOT as a bare
    // substring: `full` is an ordinary English word, so `str_contains` would accept "export the full
    // document with `--format=uir`" as naming its own replacement. That held only while the id was
    // long enough to never occur in prose, which is a property of one spelling rather than a rule.
    return preg_match('/is now "([\w.-]*\w)"/', Formats::replacementHint($id), $replacement) === 1
        && preg_match('/--format[= ]'.preg_quote($replacement[1], '/').'\b/', $line) === 1;
}

it('never tells a reader on the site to run an export format the table does not know', function (): void {
    $documented = documentedExportFormats();

    // Anti-vacuity: the scan reads one literal shape across two trees, and one that matched nothing
    // would report every page clean forever.
    expect(count($documented))->toBeGreaterThanOrEqual(12, 'the documented --format scan found nothing, so it proves nothing')
        ->and(array_filter($documented, static fn (array $row): bool => $row[1] === Formats::DEFAULT))
        ->not->toBeEmpty('no page names even the default format, so the scan is not reading what it thinks');

    $unsound = array_values(array_map(
        static fn (array $row): string => $row[0].': '.$row[1],
        array_filter($documented, static fn (array $row): bool => ! documentedFormatIsSound($row[1], $row[2])),
    ));

    expect($unsound)->toBe([], 'documented --format values naming a format the emitter table has not got');
});

it('calls a page short that names a retired format with no replacement beside it', function (): void {
    // A retired id is not banned from the documentation — the upgrade notes have to name it. What is
    // banned is naming it alone, which is a page still telling a reader to run it.
    expect(Formats::supports('uir'))->toBeFalse()
        ->and(documentedFormatIsSound('uir', '### `--format=uir` is now `--format=full`'))->toBeTrue()
        ->and(documentedFormatIsSound('uir', 'Export the full document with `--format=uir`.'))->toBeFalse()
        ->and(documentedFormatIsSound('openapi-4.0', 'Export with `--format=openapi-4.0`.'))->toBeFalse()
        ->and(documentedFormatIsSound(Formats::DEFAULT, 'Export with `--format='.Formats::DEFAULT.'`.'))->toBeTrue();
});

it('reads both spellings of the flag, and neither a signature line, another command\'s option nor a sentence about it', function (): void {
    $page = implode("\n", [
        'php artisan docuccino:export --format=openapi-3.1 --out=docs/openapi-3.1.json',
        'php artisan docuccino:export --format openapi-3.0 --out=docs/openapi-3.0.json',
        'php artisan docuccino:export --format=postman --out=docs/collection.json, then docuccino:validate it',
        '    {--format=terminal : terminal | json}',
        'Run `docuccino:diff --format=json` and you get one object instead.',
        'php artisan docuccino:diff docs/openapi.json --enforce --format=json',
        'Rename it wherever you spell it — the `--format` flag, and any `export.targets` entry.',
    ]);

    // The space form is what the `=`-only scan read straight past, and each skipped line is a reason the
    // scan is scoped rather than literal: a signature another guard owns, two lines about a command whose
    // own `--format` the emitter table does not govern, and one sentence of English. Naming export
    // alongside another command is still export's line, which is why the third one is read.
    expect(formatIdsNamedIn($page))->toBe([
        [1, 'openapi-3.1', 'php artisan docuccino:export --format=openapi-3.1 --out=docs/openapi-3.1.json'],
        [2, 'openapi-3.0', 'php artisan docuccino:export --format openapi-3.0 --out=docs/openapi-3.0.json'],
        [3, 'postman', 'php artisan docuccino:export --format=postman --out=docs/collection.json, then docuccino:validate it'],
    ]);
});
