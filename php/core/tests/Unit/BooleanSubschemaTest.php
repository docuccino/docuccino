<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Tests\Support\OpenApiMetaSchema;

/**
 * A boolean at a subschema position, at every position 2020-12 has one, through every artifact the
 * emitters produce. `false` and `true` are opposites — nothing is valid against one and everything
 * against the other — and both had been shipping as `{}`, which is one of them; then both shipped as
 * written, which OpenAPI 3.0's draft-4-shaped Schema Object refuses at every position but
 * `additionalProperties`. Neither was caught, because no test put a boolean anywhere.
 *
 * The positions are read off {@see SchemaKeywords}, so one added to the table is covered here without
 * being named, and the meta-schema is the oracle: whatever spelling a target publishes, the artifact
 * answers to its own published schema.
 */

/**
 * A one-schema document with `$value` at the subschema slot `$keyword` defines: the keyword itself for
 * a single subschema, a member for a map, index 0 for a list.
 *
 * @return array<string, mixed>
 */
function booleanSubschemaDocument(string $keyword, mixed $value): array
{
    $slot = match (SchemaKeywords::positionOf($keyword)) {
        SchemaKeywords::POSITION_SCHEMA_MAP => [$keyword => ['Inner' => $value]],
        SchemaKeywords::POSITION_SCHEMA_LIST => [$keyword => [$value]],
        default => [$keyword => $value],
    };

    return [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [],
        'components' => ['schemas' => ['S' => $slot]],
    ];
}

/** The JSON the artifact publishes for the `S` component, or `null` where it carries none. */
function booleanSubschemaPublished(string $format, array $document): string
{
    $graph = json_decode(
        Formats::emit($format, UirDocument::fromArray($document), new EmitOptions)->output,
        flags: JSON_THROW_ON_ERROR,
    );

    if ($format !== 'uir') {
        // The oracle. A spelling no validator accepts is the defect this file exists for.
        expect(OpenApiMetaSchema::findings($format, $graph))->toBe([], $format.' meta-schema');
    }

    return (string) json_encode($graph->components->schemas->S ?? null);
}

/** @return array<string, array{string}> */
function booleanSubschemaKeywords(): array
{
    $cases = [];

    foreach ([
        ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA),
        ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_MAP),
        ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST),
    ] as $keyword) {
        $cases[$keyword] = [$keyword];
    }

    return $cases;
}

it('covers every subschema position the table names', function (): void {
    // Anti-vacuity: a generator that stopped seeing a position would quietly stop proving anything
    // about it, which is how eight of these shipped inverted.
    expect(booleanSubschemaKeywords())->toHaveCount(21)
        ->and(array_keys(booleanSubschemaKeywords()))
        ->toContain('items', 'not', 'additionalProperties', 'properties', 'allOf', 'prefixItems', 'if');
});

it('publishes a boolean subschema as written in every dialect that spells one', function (string $keyword): void {
    $position = SchemaKeywords::positionOf($keyword);

    foreach ([false, true] as $value) {
        $written = $value ? 'true' : 'false';

        $expected = match ($position) {
            SchemaKeywords::POSITION_SCHEMA_MAP => '{"'.$keyword.'":{"Inner":'.$written.'}}',
            SchemaKeywords::POSITION_SCHEMA_LIST => '{"'.$keyword.'":['.$written.']}',
            default => '{"'.$keyword.'":'.$written.'}',
        };

        $document = booleanSubschemaDocument($keyword, $value);

        foreach (['uir', 'openapi-3.2', 'openapi-3.1'] as $format) {
            expect(booleanSubschemaPublished($format, $document))->toBe($expected, $keyword.' '.$written.' '.$format);
        }
    }
})->with(booleanSubschemaKeywords());

it('publishes a boolean subschema in the 3.0 spelling of the same constraint', function (string $keyword): void {
    // 3.0 defines a boolean at `additionalProperties` and nowhere else, and drops the keywords it has no
    // word for at all — so the rewrite is owed at exactly the positions that survive in between.
    $dropped = in_array($keyword, OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS, true);
    $native = $keyword === 'additionalProperties';
    $position = SchemaKeywords::positionOf($keyword);

    foreach ([false => '{"not":{}}', true => '{}'] as $value => $rewritten) {
        $value = (bool) $value;
        $written = $value ? 'true' : 'false';
        $inner = $native ? $written : $rewritten;

        $expected = match (true) {
            $dropped => '{}',
            $position === SchemaKeywords::POSITION_SCHEMA_MAP => '{"'.$keyword.'":{"Inner":'.$inner.'}}',
            $position === SchemaKeywords::POSITION_SCHEMA_LIST => '{"'.$keyword.'":['.$inner.']}',
            default => '{"'.$keyword.'":'.$inner.'}',
        };

        expect(booleanSubschemaPublished('openapi-3.0', booleanSubschemaDocument($keyword, $value)))
            ->toBe($expected, $keyword.' '.$written);
    }
})->with(booleanSubschemaKeywords());

it('reports the 3.0 rewrite where it happens, and stays quiet where the boolean is native', function (string $keyword): void {
    $dropped = in_array($keyword, OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS, true);
    $native = $keyword === 'additionalProperties';

    $report = (new OpenApi30DownlevelEmitter)
        ->emitWithReport(UirDocument::fromArray(booleanSubschemaDocument($keyword, false)))
        ->report;

    $codes = array_values(array_unique(array_map(static fn ($d): string => $d->code, $report->diagnostics)));

    $expected = match (true) {
        $dropped => ['downlevel.unsupported-keyword'],
        $native => [],
        default => ['downlevel.boolean-subschema'],
    };

    expect($codes)->toBe($expected, $keyword);
})->with(booleanSubschemaKeywords());

it('publishes an empty subschema slot as the empty schema, not as a list', function (string $keyword): void {
    // The other half of the same hazard: `[]` at a subschema slot is the empty object every time, and a
    // list nowhere. Only a LIST-valued keyword's own slot is genuinely a list, which is why the value is
    // placed at the slot the position defines rather than at the keyword.
    $position = SchemaKeywords::positionOf($keyword);

    $expected = match ($position) {
        SchemaKeywords::POSITION_SCHEMA_MAP => '{"'.$keyword.'":{"Inner":{}}}',
        SchemaKeywords::POSITION_SCHEMA_LIST => '{"'.$keyword.'":[{}]}',
        default => '{"'.$keyword.'":{}}',
    };

    $document = booleanSubschemaDocument($keyword, []);
    $dropped = in_array($keyword, OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS, true);

    foreach (['uir', 'openapi-3.2', 'openapi-3.1'] as $format) {
        expect(booleanSubschemaPublished($format, $document))->toBe($expected, $keyword.' '.$format);
    }

    expect(booleanSubschemaPublished('openapi-3.0', $document))->toBe($dropped ? '{}' : $expected, $keyword.' 3.0');
})->with(booleanSubschemaKeywords());

it('widens a value that is no schema at all to a vague-but-valid one', function (string $keyword): void {
    // Neither an object nor a boolean is a schema anywhere, so it cannot be published as written: the
    // empty schema is vague and true, and `items: 7` is a document no validator accepts.
    $position = SchemaKeywords::positionOf($keyword);

    $expected = match ($position) {
        SchemaKeywords::POSITION_SCHEMA_MAP => '{"'.$keyword.'":{"Inner":{}}}',
        SchemaKeywords::POSITION_SCHEMA_LIST => '{"'.$keyword.'":[{}]}',
        default => '{"'.$keyword.'":{}}',
    };

    $dropped = in_array($keyword, OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS, true);

    foreach ([null, 'nonsense', 7, 1.5] as $value) {
        $document = booleanSubschemaDocument($keyword, $value);

        foreach (['uir', 'openapi-3.2', 'openapi-3.1'] as $format) {
            expect(booleanSubschemaPublished($format, $document))->toBe($expected, $keyword.' '.$format);
        }

        expect(booleanSubschemaPublished('openapi-3.0', $document))->toBe($dropped ? '{}' : $expected, $keyword.' 3.0');
    }
})->with(booleanSubschemaKeywords());
