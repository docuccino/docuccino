<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Contracts\TagMapper;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\Tags\BaseTagMapper;
use Docuccino\Laravel\Tests\Fixtures\Tags\InheritedTagMapper;
use Docuccino\Laravel\Tests\Fixtures\Tags\PrefixesTags;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\EditableTagMapper;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/*
 * Fragment-cache soundness for `tags.mapper` (design §10): a configured mapper's answer lands INSIDE
 * the operation fragment, and the document's config bag holds only the class-string that named it — so
 * the fragment has to be keyed on where that answer is WRITTEN, or editing the mapper leaves the tags
 * the old body produced warm.
 *
 * The mapper the rows edit lives in a file the test owns ({@see EditableTagMapper}), because the file is
 * the whole of what the cache can notice.
 */
afterEach(function (): void {
    removeFragmentCacheDirs('tagmapper');
});

it('rebuilds an operation whose tags went through an edited mapper, rather than serving the old tags', function (): void {
    fragmentCacheDir('tagmapper');
    EditableTagMapper::write('V1');
    bindStubEngine();

    $cold = generateDocumentWithTagMapper(EditableTagMapper::MAPPER);
    expect($cold->document->toArray()['paths']['/api/forms']['get']['tags'])->toBe(['V1-Forms']);

    // The edit an application makes to its own mapper: same class, same config, different answer.
    EditableTagMapper::write('V2');
    $warm = generateDocumentWithTagMapper(EditableTagMapper::MAPPER);

    // What a build with nothing cached says — the truth the warm one owes, diagnostics included.
    fragmentCacheDir('tagmapper');
    $truth = generateDocumentWithTagMapper(EditableTagMapper::MAPPER);

    expect($warm->document->toArray()['paths']['/api/forms']['get']['tags'])->toBe(['V2-Forms'])
        ->and([(new UirEmitter)->emit($warm->document), diagnosticRecords($warm->diagnostics)])
        ->toBe([(new UirEmitter)->emit($truth->document), diagnosticRecords($truth->diagnostics)]);
});

it('keys the fragment on every file the mapper answers from, not just the class it was handed', function (): void {
    $dir = fragmentCacheDir('tagmapper');
    bindStubEngine();

    // InheritedTagMapper declares no map() of its own: a parent answers, and a trait supplies the prefix.
    $document = generateDocumentWithTagMapper(InheritedTagMapper::class)->document->toArray();
    $recorded = fragmentEntries($dir)['get /api/forms']['dependencies'];

    expect($document['paths']['/api/forms']['get']['tags'])->toBe(['Inherited: Forms']);

    foreach ([InheritedTagMapper::class, BaseTagMapper::class, PrefixesTags::class] as $class) {
        expect($recorded)->toContain((string) (new ReflectionClass($class))->getFileName());
    }
});

it('keys the fragment on the mapper the container resolved, which the config string need not name', function (): void {
    $dir = fragmentCacheDir('tagmapper');
    bindStubEngine();

    // A binding, not a class-string — the shape a mapper needing anything but constructor DI takes.
    // Its file is the only thing there is to key on: `tags.mapper` here names no class at all.
    app()->bind('tags.anonymous-mapper', static fn (): TagMapper => new class implements TagMapper
    {
        public function map(string $tag): string
        {
            return 'Bound: '.$tag;
        }
    });

    $document = generateDocumentWithTagMapper('tags.anonymous-mapper')->document->toArray();

    expect($document['paths']['/api/forms']['get']['tags'])->toBe(['Bound: Forms'])
        ->and(fragmentEntries($dir)['get /api/forms']['dependencies'])->toContain(__FILE__);
});

it('keys only the fragments whose tags went through the mapper', function (): void {
    $dir = fragmentCacheDir('tagmapper');
    $mapperFile = EditableTagMapper::write('V1');
    bindStubEngine();

    $document = generateDocumentWithTagMapper(EditableTagMapper::MAPPER)->document->toArray();
    $entries = fragmentEntries($dir);

    // /api/ping is a closure route with no #[Group]: no controller name to derive a default tag from,
    // so nothing on it ever reached the mapper.
    expect($document['paths']['/api/ping']['get'])->not->toHaveKey('tags')
        ->and($entries['get /api/forms']['dependencies'])->toContain($mapperFile)
        ->and($entries['get /api/ping']['dependencies'])->not->toContain($mapperFile);

    // And the manifests answer that way: the edit retires the tagged operation and leaves the other warm.
    EditableTagMapper::write('V2');

    expect(fragmentEntryFresh($dir, $entries['get /api/forms']['key']))->toBeFalse()
        ->and(fragmentEntryFresh($dir, $entries['get /api/ping']['key']))->toBeTrue();
});

it('keys a webhook fragment on the mapper that shaped its tags', function (): void {
    // A webhook is document-level and has no route, and it is cached exactly as a route's operation is —
    // so a mapper that tagged one has to key it there too.
    app()->setBasePath(dirname(__DIR__, 2));
    $dir = fragmentCacheDir('tagmapper');
    setBuild('documents.default.webhooks.dir', 'workbench/app/Webhooks');
    $mapperFile = EditableTagMapper::write('V1');
    bindStubEngine();

    $document = generateDocumentWithTagMapper(EditableTagMapper::MAPPER)->document->toArray();

    expect($document['webhooks']['form.submitted']['post']['tags'])->toBe(['V1-Forms'])
        ->and(fragmentEntries($dir)['post form.submitted']['dependencies'])->toContain($mapperFile);
});

it('serves every fragment warm when the mapper is left alone', function (): void {
    fragmentCacheDir('tagmapper');
    EditableTagMapper::write('V1');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocumentWithTagMapper(EditableTagMapper::MAPPER);
    $engine->analyzeCount = 0;

    // Keying on a file the cache can SEE and not read costs every tagged operation a rebuild on every
    // build — the manifest has no digest to compare, so nothing it holds ever reads fresh again.
    generateDocumentWithTagMapper(EditableTagMapper::MAPPER);

    expect($engine->analyzeCount)->toBe(0);
});

it('refuses to cache the operations a mapper with no file tagged, and says which mapper', function (): void {
    $dir = fragmentCacheDir('tagmapper');
    bindStubEngine();

    // eval()'d code reports a file like `/path/Test.php(12) : eval()'d code`, which is a path no
    // `is_file()` matches — and a manifest records a file that isn't there as ABSENT, which reads FRESH
    // for as long as it stays absent. So there is nothing here to key a fragment on.
    if (! class_exists('Docuccino\Laravel\Tests\Temp\EvaldTagMapper', false)) {
        eval('namespace Docuccino\Laravel\Tests\Temp; class EvaldTagMapper implements \Docuccino\Core\Extensions\Contracts\TagMapper { public function map(string $tag): string { return "Evald: ".$tag; } }');
    }

    $result = generateDocumentWithTagMapper('Docuccino\Laravel\Tests\Temp\EvaldTagMapper');
    $entries = fragmentEntries($dir);

    expect($result->document->toArray()['paths']['/api/forms']['get']['tags'])->toBe(['Evald: Forms'])
        // The document is still right — only its cacheability is gone, and for the tagged operations only.
        ->and($entries)->not->toHaveKey('get /api/forms')
        ->and($entries)->toHaveKey('get /api/ping');

    $reported = diagnosticsCoded($result->diagnostics, 'config.tag-mapper-unhashable');

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->message)->toContain('EvaldTagMapper');
});
