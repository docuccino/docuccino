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
 */

/** Every `docuccino:export --format=<id>` written into a package's own source, by file. */
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
                '/docuccino:export[^\r\n]{0,80}?--format=([\w.-]+)/',
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
