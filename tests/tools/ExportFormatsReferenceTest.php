<?php

declare(strict_types=1);

use Docuccino\Core\Emit\Formats;

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
        ->and($ids)->toContain(Formats::DEFAULT, 'uir', 'postman')
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

    $tab = substr($page, (int) strpos($page, 'YAML (a serialization)'), 600);

    $unmentioned = array_values(array_filter(
        $jsonOnly,
        static fn (string $id): bool => stripos($tab, $id === 'uir' ? 'UIR' : $id) === false,
    ));

    expect($unmentioned)->toBe([], 'formats with no YAML serialisation that the tab never warns about');
});
