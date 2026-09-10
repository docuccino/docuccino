<?php

declare(strict_types=1);

/*
 * The union guard over the two meta-schema oracles.
 *
 * `php/core/tests/Unit/OpenApiMetaSchemaTest` validates the UIR documents under core's fixture tree
 * and `php/laravel/tests/Unit/OpenApiMetaSchemaTest` those under the adapter's. Each proves its own
 * half and is silent outside it, and side by side they covered their two subsets and nothing between:
 * five recorded UIR goldens sat outside both globs, emitted by nothing, with the whole suite green.
 *
 * So the halves are asserted against the DOMAIN rather than against each other. The rule is stated
 * here independently — a UIR document belongs to whichever package's fixture tree holds it, and there
 * is no third place — instead of asking either file which files it happened to pick up, which would
 * agree with whatever those files do.
 */
it('leaves no UIR document in the tree outside an oracle', function (): void {
    $documents = uirDocuments();

    $core = [];
    $adapter = [];
    $orphans = [];

    foreach ($documents as $path) {
        match (true) {
            str_contains($path, '/php/core/tests/Fixtures/') => $core[] = $path,
            str_contains($path, '/php/laravel/tests/Fixtures/golden/') => $adapter[] = $path,
            default => $orphans[] = $path,
        };
    }

    // The failure this exists for: a document in neither half is emitted by nothing. It is named
    // rather than counted, because the fix is to put that file somewhere an oracle reads.
    expect($orphans)->toBe([])
        // A scan that matched nothing would satisfy the line above. Both halves are non-empty, and
        // both floors are close under what the tree holds today — 14 and 20.
        ->and(count($core))->toBeGreaterThanOrEqual(12)
        ->and(count($adapter))->toBeGreaterThanOrEqual(18)
        ->and(count($core) + count($adapter))->toBe(count($documents));
});

/**
 * The positive control for the predicate above: a path in neither half really is refused. Executed
 * rather than asserted, because "the default arm cannot be reached" is exactly what was true of the
 * five goldens right up until it was not.
 */
it('calls a UIR document outside both fixture trees an orphan', function (): void {
    $classify = static fn (string $path): string => match (true) {
        str_contains($path, '/php/core/tests/Fixtures/') => 'core',
        str_contains($path, '/php/laravel/tests/Fixtures/golden/') => 'adapter',
        default => 'orphan',
    };

    expect($classify('/repo/php/core/tests/Fixtures/golden/worked-example.uir.json'))->toBe('core')
        ->and($classify('/repo/php/laravel/tests/Fixtures/golden/workbench.uir.json'))->toBe('adapter')
        // The two shapes that have to fail: a new package, and a stray beside an existing tree.
        ->and($classify('/repo/php/inference-phpstan/tests/Fixtures/thing.uir.json'))->toBe('orphan')
        ->and($classify('/repo/php/laravel/tests/Fixtures/thing.uir.json'))->toBe('orphan');
});

/** And the discovery itself is not blind: it finds documents, and only documents. */
it('discovers UIR documents and nothing else', function (): void {
    $documents = uirDocuments();

    expect(count($documents))->toBeGreaterThanOrEqual(30);

    foreach ($documents as $path) {
        $decoded = json_decode((string) file_get_contents($path), true);

        expect(is_array($decoded) && isset($decoded['uir'], $decoded['info']))->toBeTrue($path);
    }

    // The JSON in these trees that is NOT a UIR document — a Postman schema, an emitted artifact —
    // must stay out, or the halves above are counting the wrong population.
    expect($documents)->not->toContain(dirname(__DIR__, 2).'/php/core/tests/Fixtures/postman-collection-v2.1.0.schema.json')
        ->and($documents)->not->toContain(dirname(__DIR__, 2).'/php/laravel/tests/Fixtures/golden/workbench.openapi.json');
});
