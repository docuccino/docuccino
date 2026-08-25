<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;

/*
 * The guard behind "what a 3.0 export changes". The page's table is a completeness claim closing on
 * "everything the two tables don't name is identical to the 3.2 output", and it had drifted twice over:
 * it named five of the eighteen keywords 3.0 cannot express, and it said nothing at all about the losses
 * a 3.0 export inherits by being produced through the 3.1 one.
 *
 * Two sources of truth, because the two emitters expose themselves differently. The 3.0 half reads
 * UNSUPPORTED_SCHEMA_KEYWORDS, which is public. The 3.1 half has no equivalent constant — its tag members
 * sit in a private table and `query`/`additionalOperations` are inline literals — so it reads the
 * `downlevel.*` codes out of that emitter's source instead, which is what a reader would be shown anyway.
 */

function firstExportSource(): string
{
    return (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/getting-started/first-export.mdx',
    );
}

/** The `downlevel.*` codes the 3.1 emitter raises — every loss a 3.0 export inherits from it. */
function inheritedDownlevelCodes(): array
{
    $source = (string) file_get_contents(
        dirname(__DIR__, 2).'/php/core/src/Emit/OpenApi31DownlevelEmitter.php',
    );

    preg_match_all("/'(downlevel\.[\w-]+)'/", $source, $matches);

    $codes = array_values(array_unique($matches[1]));
    sort($codes);

    return $codes;
}

/** The section of the page covering the 3.0 export, so a mention elsewhere cannot cover for a missing row. */
function downlevelSection(): string
{
    $page = firstExportSource();
    $start = strpos($page, '### OpenAPI 3.0 export');

    expect($start)->not->toBeFalse('the 3.0 export section is gone');

    return substr($page, (int) $start);
}

it('reads a plausible number of unsupported keywords', function (): void {
    // A constant that stopped being readable would make the row check below vacuous.
    expect(count(OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS))->toBeGreaterThanOrEqual(15)
        ->and(OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS)
        ->toContain('if', 'then', 'else', 'prefixItems', 'unevaluatedProperties');
});

it('names every keyword 3.0 cannot express', function (): void {
    $section = downlevelSection();

    $missing = array_values(array_filter(
        OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS,
        static fn (string $keyword): bool => ! str_contains($section, '`'.$keyword.'`'),
    ));

    expect($missing)->toBe([], 'keywords dropped by the 3.0 emitter that the page never mentions');
});

it('says nothing about a keyword the emitter drops silently', function (): void {
    // SILENT_SCHEMA_KEYWORDS carries no consumer-visible meaning and raises no diagnostic, so a row for
    // one would promise a `downlevel.*` note the reader will never see.
    $section = downlevelSection();

    $overclaimed = array_values(array_filter(
        OpenApi30DownlevelEmitter::SILENT_SCHEMA_KEYWORDS,
        static fn (string $keyword): bool => str_contains($section, '`'.$keyword.'`'),
    ));

    expect($overclaimed)->toBe([], 'keywords the page lists as reported that are dropped without a note');
});

it('accounts for every loss a 3.0 export inherits from the 3.1 one', function (): void {
    // Each 3.1 code, and the member a reader would scan the table for. The map is the assertion: a new
    // code in that emitter has no entry here and fails, rather than quietly going undocumented.
    $subjects = [
        'downlevel.additional-operations' => '`additionalOperations`',
        'downlevel.query-method' => '`query` HTTP method',
        'downlevel.tag-kind' => "A tag's `kind`",
        'downlevel.tag-parent' => "A tag's `parent`",
        'downlevel.tag-summary' => "A tag's `summary`",
    ];

    $codes = inheritedDownlevelCodes();

    expect($codes)->not->toBeEmpty()
        ->and($codes)->toBe(array_keys($subjects), 'the 3.1 emitter raises a code this guard has no row for');

    $section = downlevelSection();

    $missing = array_values(array_filter(
        $subjects,
        static fn (string $subject): bool => ! str_contains($section, $subject),
    ));

    expect($missing)->toBe([], 'losses inherited from the 3.1 emitter that the 3.0 section never states')
        ->and($section)->toContain('produced **through** the 3.1 one');
});

it('does not claim the rest of the export is identical to 3.2 without qualifying it', function (): void {
    // The sentence that made the tables a completeness claim in the first place. It is only true of what
    // the tables name, and a 3.0 export loses the 3.1 members too.
    $section = downlevelSection();

    expect($section)->not->toContain('Everything else is identical to the 3.2 output')
        ->and($section)->toContain("Everything the two tables don't name is identical to the 3.2 output");
});
