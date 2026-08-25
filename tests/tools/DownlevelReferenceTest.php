<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Tests\Support\OpenApiMetaSchema;

/*
 * The guard behind "what a 3.0 export changes". The page's table is a completeness claim closing on
 * "everything the two tables don't name is identical to the 3.2 output", and it had drifted twice over:
 * it named five of the eighteen keywords 3.0 cannot express, and it said nothing at all about the losses
 * a 3.0 export inherits by being produced through the 3.1 one.
 *
 * Both halves read the same source of truth for the same reason: what a reader is SHOWN of a loss is its
 * `downlevel.*` diagnostic, so the codes an emitter raises are the losses the page owes a row. Reading
 * one emitter's keyword constant instead covers a single code of its eighteen, which is how a brand-new
 * 3.0 drop — an endpoint dropped for an unresolvable shared path item — went undocumented with this file
 * green. The keyword constant is still read, one level finer: it says the big row lists every keyword.
 */

function firstExportSource(): string
{
    return (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/getting-started/first-export.mdx',
    );
}

/**
 * The `downlevel.*` codes one emitter raises, read out of its source because neither exposes them as a
 * constant — the tag members sit in a private table and most subjects are inline literals.
 *
 * @return list<string>
 */
function downlevelCodes(string $emitter): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/php/core/src/Emit/'.$emitter.'.php');

    preg_match_all("/'(downlevel\.[\w-]+)'/", $source, $matches);

    $codes = array_values(array_unique($matches[1]));
    sort($codes);

    return $codes;
}

/** The rows of the 3.0 section's tables, where a loss the reader will see a diagnostic for belongs. */
function downlevelTableRows(): string
{
    $rows = array_filter(
        explode("\n", downlevelSection()),
        static fn (string $line): bool => str_starts_with(trim($line), '|'),
    );

    // Anti-vacuity: a scan that stopped seeing the tables would otherwise pass forever.
    expect(count($rows))->toBeGreaterThanOrEqual(20);

    return implode("\n", $rows);
}

/** The section of the page covering the 3.0 export, so a mention elsewhere cannot cover for a missing row. */
function downlevelSection(): string
{
    $page = firstExportSource();
    $start = strpos($page, '### OpenAPI 3.0 export');

    expect($start)->not->toBeFalse('the 3.0 export section is gone');

    return substr($page, (int) $start);
}

it('reads every unsupported keyword there is, not a plausible number of them', function (): void {
    // A constant that stopped being readable would make the row check below vacuous, and a hand list of
    // five names is only worth those five: dropping `prefixItems` failed here and dropping `maxContains`
    // did not. So the floor is the SET, derived from the two things that decide it — every schema keyword
    // the product knows, less the ones 3.0's own Schema Object enumerates, less the ones this emitter
    // rewrites instead of dropping.
    $defined = array_keys(get_object_vars(OpenApiMetaSchema::decode('openapi-3.0')->definitions->Schema->properties));

    $owed = array_values(array_filter(
        schemaKeywordVocabulary(),
        static fn (string $keyword): bool => ! str_starts_with($keyword, 'x-')
            && ! in_array($keyword, $defined, true)
            && ! in_array($keyword, OpenApi30DownlevelEmitter::HANDLED_SCHEMA_KEYWORDS, true),
    ));

    $dropped = [
        ...OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS,
        ...OpenApi30DownlevelEmitter::SILENT_SCHEMA_KEYWORDS,
    ];
    sort($dropped);

    // Anti-vacuity on the derivation itself: a decode or a scan that stopped finding its members would
    // otherwise make the comparison a test of nothing.
    expect(count($defined))->toBe(35)
        ->and(count($owed))->toBeGreaterThan(15)
        ->and($owed)->toBe($dropped, 'a keyword 3.0 cannot express that this emitter no longer drops');
});

it('names every keyword 3.0 cannot express', function (): void {
    $section = downlevelSection();

    $missing = array_values(array_filter(
        OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS,
        static fn (string $keyword): bool => ! str_contains($section, '`'.$keyword.'`'),
    ));

    expect($missing)->toBe([], 'keywords dropped by the 3.0 emitter that the page never mentions');
});

it('keeps a keyword the emitter drops silently out of the tables', function (): void {
    // SILENT_SCHEMA_KEYWORDS carries no consumer-visible meaning and raises no diagnostic, so a ROW for
    // one would promise a `downlevel.*` note the reader will never see. The prose may still name it —
    // the completeness claim closing the section is about losses, and a silent one is still a loss.
    $rows = downlevelTableRows();

    $overclaimed = array_values(array_filter(
        OpenApi30DownlevelEmitter::SILENT_SCHEMA_KEYWORDS,
        static fn (string $keyword): bool => str_contains($rows, '`'.$keyword.'`'),
    ));

    expect($overclaimed)->toBe([], 'keywords the tables list as reported that are dropped without a note')
        ->and(downlevelSection())->toContain('`$comment` is dropped without a note');
});

it('accounts for every loss the 3.0 emitter reports', function (): void {
    // Each code that emitter raises, and the subject a reader would scan the tables for. The map is the
    // assertion: a new code has no entry here and fails, rather than quietly going undocumented — which
    // is exactly what a guard reading only UNSUPPORTED_SCHEMA_KEYWORDS let through, since that constant
    // answers for one of the eighteen.
    $subjects = [
        'downlevel.boolean-subschema' => 'A subschema of `true` or `false`',
        'downlevel.component-path-items' => '`components.pathItems`',
        'downlevel.const' => '`const: "draft"`',
        'downlevel.content-encoding' => '`contentEncoding: base64`',
        'downlevel.empty-responses' => 'An operation with no responses',
        'downlevel.exclusive-bound' => '`exclusiveMinimum: 0`',
        'downlevel.info-summary' => '`info.summary`',
        'downlevel.license-identifier' => 'An SPDX `info.license.identifier`',
        'downlevel.multi-type' => '`type: [string, integer]`',
        'downlevel.mutual-tls' => 'A `mutualTLS` security scheme',
        'downlevel.null-type' => '`type: [string, null]`',
        'downlevel.nullable-composition' => '`anyOf: [{$ref}, {type: null}]`',
        'downlevel.path-item-ref' => 'A path `$ref`-ing a shared path item',
        'downlevel.path-item-unresolved' => 'A path whose `$ref` chain reaches no shared path item',
        'downlevel.ref-siblings' => 'A `description` beside a `$ref`',
        'downlevel.schema-examples' => 'Schema `examples: [a, b]`',
        // The row itself; that it lists every keyword is the finer check above.
        'downlevel.unsupported-keyword' => 'Dropped — 3.0 cannot express them',
        'downlevel.webhooks' => '`webhooks`',
    ];

    $codes = downlevelCodes('OpenApi30DownlevelEmitter');

    expect($codes)->not->toBeEmpty()
        ->and($codes)->toBe(array_keys($subjects), 'the 3.0 emitter raises a code this guard has no row for');

    $rows = downlevelTableRows();

    $missing = array_values(array_filter(
        $subjects,
        static fn (string $subject): bool => ! str_contains($rows, $subject),
    ));

    expect($missing)->toBe([], 'losses the 3.0 emitter reports that its tables never name');
});

it('accounts for every loss a 3.0 export inherits from the 3.1 one', function (): void {
    // The same reading of the sibling emitter, whose losses a 3.0 export carries by being produced
    // through it.
    $subjects = [
        'downlevel.additional-operations' => '`additionalOperations`',
        'downlevel.query-method' => '`query` HTTP method',
        'downlevel.tag-kind' => "A tag's `kind`",
        'downlevel.tag-parent' => "A tag's `parent`",
        'downlevel.tag-summary' => "A tag's `summary`",
    ];

    $codes = downlevelCodes('OpenApi31DownlevelEmitter');

    expect($codes)->not->toBeEmpty()
        ->and($codes)->toBe(array_keys($subjects), 'the 3.1 emitter raises a code this guard has no row for');

    $rows = downlevelTableRows();

    $missing = array_values(array_filter(
        $subjects,
        static fn (string $subject): bool => ! str_contains($rows, $subject),
    ));

    expect($missing)->toBe([], 'losses inherited from the 3.1 emitter that the 3.0 tables never name')
        ->and(downlevelSection())->toContain('produced **through** the 3.1 one');
});

it('does not claim the rest of the export is identical to 3.2 without qualifying it', function (): void {
    // The sentence that made the tables a completeness claim in the first place. It is only true of what
    // the tables name, and a 3.0 export loses the 3.1 members too.
    $section = downlevelSection();

    expect($section)->not->toContain('Everything else is identical to the 3.2 output')
        ->and($section)->toContain("Everything the two tables don't name is identical to the 3.2 output");
});
