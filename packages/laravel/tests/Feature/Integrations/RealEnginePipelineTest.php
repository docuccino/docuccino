<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;

/**
 * Real-engine pipeline smoke (design §5 / §8): the actual inference-phpstan engine analyses a
 * `response()->json([...])` controller out-of-process (its Laravel/Larastan can't share the Pest
 * process), and the RECOVERED payload shape — not a hand-authored stub — is driven through the full
 * DocumentGenerator so the emitted response schema is asserted to reflect what inference found.
 * Guards the engine↔pipeline seam that stub-only tests cannot.
 *
 * NOTE (gap surfaced): the type→schema chain does not yet unwrap `JsonResponse<payload>` — the real
 * engine reports `SpikeController::jsonShape()` as `ClassT(JsonResponse, [arrayShape])`, and feeding
 * that whole type to the pipeline renders a generic `{type: object}`. Automatic JsonResponse
 * unwrapping is the deferred Phase-4 integration the workbench stub stands in for; here we assert the
 * pipeline renders the payload shape the engine actually recovered (the unwrapping's eventual input).
 */
beforeEach(function (): void {
    if (! FixtureRunner::available()) {
        getenv('DOCUCCINO_REQUIRE_FIXTURE') === '1'
            ? test()->fail('The fixture app is required (DOCUCCINO_REQUIRE_FIXTURE=1) but unavailable.')
            : test()->markTestSkipped('Fixture app not provisioned; skipping the real-engine pipeline smoke.');
    }
});

it('renders a real response()->json([...]) payload shape into the emitted response schema', function (): void {
    // The real engine recovers jsonShape() as JsonResponse<arrayShape{id,name,tags}>.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/SpikeController.php',
        'App\\Http\\Controllers\\SpikeController',
        'jsonShape',
    ));

    $returnType = $analysis->returns[0]->type ?? null;
    expect($returnType)->toBeInstanceOf(ClassT::class);

    // The payload shape the engine recovered from response()->json([...]) — real inference, no stub.
    $payload = $returnType->typeArgs[0] ?? null;
    expect($payload)->not->toBeNull();

    // Drive the recovered payload through the full pipeline (the eventual output of JsonResponse
    // unwrapping) and assert the emitted 200 response reflects it.
    $engine = new StubTypeEngine(analyses: [
        'Workbench\\App\\Http\\Controllers\\FormController::index' => new ActionAnalysis(
            returns: [new ReturnSite($payload, new SourceLocation(''))],
        ),
    ]);
    app()->instance(TypeEngine::class, $engine);

    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $config = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');
    $document = app(DocumentGenerator::class)->generate($config, $engine)->document->toArray();

    $schema = $document['paths']['/api/forms']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    // The emitted schema mirrors the real-inferred payload: an object with id/name/tags.
    expect($schema)->toBeArray()
        ->and($schema['type'] ?? null)->toBe('object')
        ->and($schema['properties'] ?? [])->toHaveKeys(['id', 'name', 'tags']);
})->group('fixture');
