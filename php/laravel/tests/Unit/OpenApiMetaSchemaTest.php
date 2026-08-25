<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Tests\Support\EmittedDocument;
use Docuccino\Core\Tests\Support\OpenApiMetaSchema;

/**
 * The meta-schema oracle over the adapter's recorded documents: what a REAL application's build produces,
 * emitted to every OpenAPI version and read back against the published schema for that version.
 *
 * Core's own fixtures are hand-written and small; these are the whole workbench, so they carry shapes no
 * hand-written fixture reaches — and the first run of this file found one: thirteen operations across
 * seven of these documents recovered no responses, which 3.1 and 3.2 accept and 3.0 requires. The 3.0
 * emitter answers that with a placeholder `default` response now, so nothing here is pinned.
 *
 * JSON only, for now. The YAML half of the same battery is blocked on the `YamlSerializer` cast that
 * writes every empty map as `[]`: these documents hold empty maps at eight positions, so a YAML pass here
 * would land eight pinned exceptions that the fix immediately deletes. Core's oracle covers the YAML path
 * (`OpenApiMetaSchemaTest`, on a document that produces one such map through the 3.0 downlevel emitter),
 * and this file gains its YAML half with the fix.
 */

/** @return array<string, array{string, string}> */
function adapterMetaSchemaSubjects(): array
{
    $subjects = [];

    foreach (adapterMetaSchemaGoldens() as $golden) {
        foreach (array_keys(OpenApiMetaSchema::SCHEMAS) as $format) {
            $subjects[basename($golden, '.uir.json').' · '.$format] = [$golden, $format];
        }
    }

    return $subjects;
}

/**
 * Every recorded UIR golden, discovered rather than listed, so a document added tomorrow is validated
 * without anyone remembering to name it here.
 *
 * @return list<string>
 */
function adapterMetaSchemaGoldens(): array
{
    $goldens = [];

    foreach (glob(golden('*.uir.json')) ?: [] as $path) {
        $goldens[] = basename($path);
    }

    sort($goldens);

    return $goldens;
}

it('emits JSON that answers to its own OpenAPI meta-schema', function (string $golden, string $format): void {
    $document = UirDocument::fromArray(json_decode(
        (string) file_get_contents(golden($golden)),
        true,
        flags: JSON_THROW_ON_ERROR,
    ));

    $emitted = json_decode(Formats::emit($format, $document, new EmitOptions)->output, flags: JSON_THROW_ON_ERROR);

    expect(OpenApiMetaSchema::findings($format, $emitted))->toBe([]);
})->with(adapterMetaSchemaSubjects());

/**
 * A scan that finds nothing must fail. Well under what the tree holds today, far enough above zero that a
 * glob which stopped matching fails here instead of passing on an empty battery.
 */
it('validates a plausible minimum of recorded documents and positions', function (): void {
    $positions = 0;

    foreach (adapterMetaSchemaGoldens() as $golden) {
        $positions += EmittedDocument::nodes(json_decode(
            (string) file_get_contents(golden($golden)),
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    expect(count(adapterMetaSchemaGoldens()))->toBeGreaterThanOrEqual(10)
        ->and(count(adapterMetaSchemaSubjects()))->toBeGreaterThanOrEqual(30)
        ->and($positions)->toBeGreaterThanOrEqual(10000);
});
