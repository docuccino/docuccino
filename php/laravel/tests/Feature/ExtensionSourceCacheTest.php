<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\EditableOperationExtension;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/*
 * Fragment-cache soundness for an extension's own BODY (design §10). An extension writes into the
 * operation fragment, and the signature keying that fragment paired each instance with its composer
 * package's version — sound for a package, and inert for a class in the application's own tree, whose
 * "package" is the root and whose version does not move when a file is saved. So the primary extension
 * point was the one collaborator whose edit the cache could not see.
 *
 * The extension the rows edit lives in files the test owns ({@see EditableOperationExtension}), because
 * the bytes are the whole of what the cache can notice.
 */
afterEach(function (): void {
    removeFragmentCacheDirs('extsource');
});

it('rebuilds an operation an edited extension shaped, rather than serving the answer its old body gave', function (): void {
    fragmentCacheDir('extsource');
    EditableOperationExtension::write('V1');
    Docuccino::extend(EditableOperationExtension::EXTENSION);
    bindStubEngine();

    $scratch = static fn (array $document): mixed => $document['paths']['/api/forms']['get'][EditableOperationExtension::FIELD] ?? null;

    $cold = generateDocument();
    expect($scratch($cold->document->toArray()))->toBe('M1:V1');

    // The edit an application makes to its own extension: same class, same registration, same config,
    // different answer.
    EditableOperationExtension::write('V2');
    $warm = generateDocument();

    // What a build with nothing cached says — the truth the warm one owes, diagnostics included.
    fragmentCacheDir('extsource');
    $truth = generateDocument();

    expect($scratch($warm->document->toArray()))->toBe('M1:V2')
        ->and([(new UirEmitter)->emit($warm->document), diagnosticRecords($warm->diagnostics)])
        ->toBe([(new UirEmitter)->emit($truth->document), diagnosticRecords($truth->diagnostics)]);
});

it('keys an extension on every file its answer is written in, and not only the class it was handed', function (): void {
    $dir = fragmentCacheDir('extsource');
    EditableOperationExtension::write('V1', 'M1');
    Docuccino::extend(EditableOperationExtension::EXTENSION);
    bindStubEngine();

    generateDocument();
    $before = fragmentKeys($dir);
    expect($before)->not->toBe([]);

    // Half of what handle() writes comes from a trait, and PHP reports a trait-imported method as the
    // using class's — so the file holding it is reachable no other way. Rewriting only that file changes
    // no byte the loaded process can publish, which is why the row asks what the cache had to write
    // again rather than what the document said.
    EditableOperationExtension::writeTrait('M2');
    generateDocument();

    // Every operation was filed under a new key, so not one of the stored answers could be served.
    expect(array_diff(fragmentKeys($dir), $before))->toHaveCount(count($before));
});

it('serves every fragment warm when an extension is rewritten with the bytes it already had', function (): void {
    fragmentCacheDir('extsource');
    EditableOperationExtension::write('V1');
    Docuccino::extend(EditableOperationExtension::EXTENSION);
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument();
    expect($engine->analyzeCount)->toBeGreaterThan(0);
    $engine->analyzeCount = 0;

    // What a reinstall does: the same bytes land in the same files with new timestamps. The digest is
    // taken over CONTENT and nothing else, so a `composer install` — or a `composer update` bumping a
    // package that never touched this extension — costs no application a rebuild. The timestamps are
    // moved explicitly, since two writes inside one second would agree on them anyway and the row would
    // then pass whether the digest read them or not.
    EditableOperationExtension::write('V1');
    touch(EditableOperationExtension::file(), time() + 60);
    touch(EditableOperationExtension::traitFile(), time() + 60);
    generateDocument();

    expect($engine->analyzeCount)->toBe(0);
});

it('retires every fragment when an extension is edited, because nothing can say which of them it shaped', function (): void {
    $dir = fragmentCacheDir('extsource');
    EditableOperationExtension::write('V1');
    Docuccino::extend(EditableOperationExtension::EXTENSION);
    bindStubEngine();

    generateDocument();
    $before = fragmentKeys($dir);
    expect(count($before))->toBeGreaterThan(3);

    // Stated plainly rather than implied: an operation extension is run over every operation, and
    // nothing in the build records which of them its answer reached — an extension that wrote nothing
    // still ran, and withholding a value is an answer too. So the key is the document's, and an edit
    // costs the whole document one rebuild. That is the blast radius the paired package version has
    // always had, not a new one; a route the mapper never answered for stays warm because a mapper is
    // asked per route, and an extension is not.
    EditableOperationExtension::write('V2');
    generateDocument();

    // Not one of them: the key moved for every operation, whether the extension shaped it or not. An
    // entry the key no longer addresses is simply never asked for again, which is why this counts what
    // the second build had to write rather than what the manifests say about themselves.
    expect(array_diff(fragmentKeys($dir), $before))->toHaveCount(count($before));
});

it('refuses the whole document the cache for an extension declared in no file, and says which', function (): void {
    $dir = fragmentCacheDir('extsource');
    bindStubEngine();

    // eval()'d code reports a file like `/path/Test.php(12) : eval()'d code`, which is a path no
    // `is_file()` matches — and a manifest records a file that isn't there as ABSENT, which reads FRESH
    // for as long as it stays absent. So there is nothing here to key a fragment on, and unlike a tag
    // mapper there is no per-route bag to refuse with: the entry keys every fragment of the document.
    if (! class_exists('Docuccino\Laravel\Tests\Temp\EvaldExtension', false)) {
        eval('namespace Docuccino\Laravel\Tests\Temp; class EvaldExtension implements \Docuccino\Core\Extensions\Contracts\OperationExtension { public function phase(): \Docuccino\Core\Extensions\Contracts\OperationPhase { return \Docuccino\Core\Extensions\Contracts\OperationPhase::Finalize; } public function handle(\Docuccino\Core\Draft\OperationDraft $operation, \Docuccino\Core\Extensions\Context\RouteContext $context): void { $operation->set("x-scratch", "Evald", \Docuccino\Core\Patch\Contribution::attribute()); } }');
    }

    Docuccino::extend('Docuccino\Laravel\Tests\Temp\EvaldExtension');
    $result = generateDocument();

    // The document is still right — only its cacheability is gone.
    expect($result->document->toArray()['paths']['/api/forms']['get'][EditableOperationExtension::FIELD] ?? null)->toBe('Evald')
        ->and(fragmentEntries($dir))->toBe([]);

    $reported = diagnosticsCoded($result->diagnostics, 'extension.unhashable');

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->message)->toContain('EvaldExtension');
});
