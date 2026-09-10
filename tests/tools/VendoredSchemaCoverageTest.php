<?php

declare(strict_types=1);

use Docuccino\Core\SpecValidation\OpenApiMetaSchema;
use Docuccino\Core\Tests\Support\PostmanSchema;

/*
 * The union guard over the schema pins, in the shape `MetaSchemaCoverageTest` uses for the UIR
 * oracles and for the reason that file already names: a guard derived from a subset is silent
 * outside it.
 *
 * Three guards pin schemas here. `OpenApiMetaSchemaTest` pins the three OpenAPI meta-schemas by
 * identity and by content; `PostmanSchemaPinTest` pins the Postman collection schema the same way;
 * `SchemaShippingTest` and `website/scripts/sync-schema.mjs --check` hold the three copies of our OWN
 * UIR schema byte-identical to the canonical one. Each proves its own members and is silent outside
 * them — and side by side they left one member unpinned for as long as it has existed. The Postman
 * schema was vendored with the emitter, read as the only structural oracle over every emitted
 * collection, and answerable to nothing: a byte edited anywhere in its 55 KB, or a dialect lift that
 * dropped its constraints, left the whole suite green.
 *
 * So the guards are asserted against the DOMAIN rather than against each other. The rule is stated
 * here independently — a JSON Schema document in this repository is a vendored third-party schema,
 * which owes both pins, or it is our own UIR schema, which owes a byte-drift guard, and there is no
 * third kind — instead of asking any of those files which schemas it happened to pick up.
 */

/**
 * Which pin a schema document owes, decided by the identity it declares rather than by where it sits.
 * A vendored file moved to a new directory must keep owing its pin.
 */
function schemaPinOwed(string $declaredId): string
{
    return match (true) {
        str_starts_with($declaredId, 'https://spec.openapis.org/oas/') => 'openapi',
        $declaredId === PostmanSchema::PUBLISHED => 'postman',
        $declaredId === 'https://spec.docuccino.app/uir/1.0/schema.json' => 'uir',
        default => 'unpinned',
    };
}

/** The id a schema document declares — draft 2020-12 spells it `$id`, draft-04 `id`. */
function declaredSchemaId(string $path): string
{
    $decoded = json_decode((string) file_get_contents($path), true);
    $declared = is_array($decoded) ? ($decoded['$id'] ?? $decoded['id'] ?? null) : null;

    return is_string($declared) ? $declared : '';
}

it('leaves no schema in the tree outside a pin', function (): void {
    $documents = schemaDocuments();

    $buckets = ['openapi' => [], 'postman' => [], 'uir' => [], 'unpinned' => []];

    foreach ($documents as $path) {
        $buckets[schemaPinOwed(declaredSchemaId($path))][] = $path;
    }

    // The failure this exists for. Named rather than counted: the fix is to pin that file, and
    // whoever reads the failure needs to know which one.
    expect($buckets['unpinned'])->toBe([])
        // A scan that matched nothing would satisfy the line above. Every bucket is non-empty and
        // every floor is close under what the tree holds today — 3, 1 and 3.
        ->and(count($buckets['openapi']))->toBeGreaterThanOrEqual(3)
        ->and(count($buckets['postman']))->toBeGreaterThanOrEqual(1)
        ->and(count($buckets['uir']))->toBeGreaterThanOrEqual(3)
        ->and(count($documents))->toBe(array_sum(array_map(count(...), $buckets)));
});

/**
 * The positive control for the predicate above, executed rather than asserted — "nothing can reach the
 * default arm" is exactly what was true of the Postman schema right up until somebody looked.
 *
 * The third row is the one that matters: a NEW third-party schema is unpinned by default. A vendored
 * AsyncAPI or JSON:API schema, or Postman's own v2.0.0 at a URI one character away, has to fail the
 * guard above rather than be quietly adopted by a bucket.
 */
it('calls a schema that owes a pin and has none unpinned', function (): void {
    expect(schemaPinOwed('https://spec.openapis.org/oas/3.2/schema/2025-09-17'))->toBe('openapi')
        ->and(schemaPinOwed(PostmanSchema::PUBLISHED))->toBe('postman')
        ->and(schemaPinOwed('https://spec.docuccino.app/uir/1.0/schema.json'))->toBe('uir')
        // The shapes that have to fail.
        ->and(schemaPinOwed('https://schema.getpostman.com/json/collection/v2.0.0/'))->toBe('unpinned')
        ->and(schemaPinOwed('https://asyncapi.com/definitions/3.0.0/asyncapi.json'))->toBe('unpinned')
        ->and(schemaPinOwed('https://spec.docuccino.app/uir/2.0/schema.json'))->toBe('unpinned')
        ->and(schemaPinOwed(''))->toBe('unpinned');
});

/**
 * And the pins are held to the domain in the other direction: every file a pin NAMES is a file the
 * discovery found. A pin pointing at a path that no longer exists passes its own test — the OpenAPI
 * one reads `OpenApiMetaSchema::path()`, the Postman one `PostmanSchema::path()` — while covering
 * nothing.
 */
it('pins nothing that is not in the tree', function (): void {
    $documents = schemaDocuments();

    $pinned = [PostmanSchema::path()];

    foreach (array_keys(OpenApiMetaSchema::SCHEMAS) as $format) {
        $pinned[] = OpenApiMetaSchema::path($format);
    }

    expect($pinned)->toHaveCount(4);

    foreach ($pinned as $path) {
        expect($documents)->toContain($path);
    }
});

/** And the discovery itself is not blind: it finds schemas, and only schemas. */
it('discovers schema documents and nothing else', function (): void {
    $documents = schemaDocuments();

    expect(count($documents))->toBeGreaterThanOrEqual(7);

    foreach ($documents as $path) {
        $decoded = json_decode((string) file_get_contents($path), true);

        expect(is_array($decoded) && is_string($decoded['$schema'] ?? null))->toBeTrue($path)
            ->and(declaredSchemaId($path))->not->toBe('', $path);
    }

    // The JSON in these trees that is NOT a schema — a UIR document, an emitted artifact — must stay
    // out, or the buckets above are counting the wrong population.
    $root = dirname(__DIR__, 2);

    expect($documents)->not->toContain($root.'/php/laravel/tests/Fixtures/golden/workbench.uir.json')
        ->and($documents)->not->toContain($root.'/composer.json');
});

/**
 * And a GENERATED schema is not a vendored one. The docs site's build writes a collection schema of
 * its own under `website/.astro/`, which declares a `json-schema.org` dialect and an `$id` no pin
 * names — so a scan reading the filesystem called it a third-party schema owing a pin, and the guard
 * above failed on every tree where the site had been built and passed on every tree where it had not.
 *
 * Executed rather than argued: the file is written where the real one lands and the scan has to miss
 * it. The tracked schemas found alongside are the positive control, so a scan returning nothing
 * cannot pass this.
 */
it('counts no generated schema among the ones a pin is owed for', function (): void {
    $root = dirname(__DIR__, 2);
    $generated = $root.'/website/.astro/collections/docuccino-generated-probe.schema.json';

    @mkdir(dirname($generated), 0755, true);
    file_put_contents($generated, (string) json_encode([
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        '$id' => 'https://example.test/generated/probe.schema.json',
        'type' => 'object',
    ]));

    try {
        $documents = schemaDocuments();

        expect($documents)->not->toContain($generated)
            ->and(count($documents))->toBeGreaterThanOrEqual(7);
    } finally {
        @unlink($generated);
    }
});
