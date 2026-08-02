<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * The OperationFragment cache (design §10): warm hits are byte-identical and skip the engine, and
 * fragments invalidate when the document config or a dependency file changes.
 */
function enableFragmentCache(): string
{
    $dir = sys_get_temp_dir().'/docuccino-fragments-'.uniqid('', true);

    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', $dir);

    return $dir;
}

function buildDefault(): UirDocument
{
    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $config = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');

    return app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/docuccino-fragments-*') ?: [] as $dir) {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }
});

it('serves a warm cache hit byte-identically while skipping the engine', function (): void {
    enableFragmentCache();
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    // Cold run: the engine is exercised and fragments are written.
    $cold = (new UirEmitter)->emit(buildDefault());
    expect($engine->analyzeCount)->toBeGreaterThan(0);

    // Warm run: every fragment is served from cache; the engine is never touched.
    $engine->analyzeCount = 0;
    $warm = (new UirEmitter)->emit(buildDefault());

    expect($warm)->toBe($cold)
        ->and($engine->analyzeCount)->toBe(0);
});

it('invalidates fragments when the document config changes', function (): void {
    enableFragmentCache();
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    buildDefault();
    $engine->analyzeCount = 0;

    // A representation policy change alters the document configHash → every key changes → miss.
    config()->set('docuccino.documents.default.representation.operation_id', 'controller-method');
    buildDefault();

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('invalidates a fragment when one of its dependency files changes', function (): void {
    enableFragmentCache();

    $dependency = sys_get_temp_dir().'/docuccino-dep-'.uniqid('', true).'.php';
    file_put_contents($dependency, '<?php // v1');

    // One route's analysis declares the temp file as a dependency; the rest use the stub default.
    $stub = new StubTypeEngine(
        analyses: [
            'Workbench\\App\\Http\\Controllers\\FormController::index' => new ActionAnalysis(
                returns: [new ReturnSite(new ListT(new ClassT('Workbench\\App\\Data\\FormData')), new SourceLocation(''))],
                dependencyFiles: [$dependency],
            ),
        ],
    );
    $engine = new CountingTypeEngine($stub);
    app()->instance(TypeEngine::class, $engine);

    buildDefault();
    $engine->analyzeCount = 0;

    // Touch the dependency: its stored hash no longer matches → the dependent fragment invalidates.
    file_put_contents($dependency, '<?php // v2');
    buildDefault();

    expect($engine->analyzeCount)->toBeGreaterThan(0);

    @unlink($dependency);
});

it('is a no-op when disabled (default): the engine runs on every build', function (): void {
    // cache.enabled defaults to false; no cache directory is configured.
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    buildDefault();
    $first = $engine->analyzeCount;
    expect($first)->toBeGreaterThan(0);

    $engine->analyzeCount = 0;
    buildDefault();

    expect($engine->analyzeCount)->toBe($first);
});
