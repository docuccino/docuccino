<?php

declare(strict_types=1);

use Docuccino\Core\Document\DocumentExtension;
use Docuccino\Core\Document\DocumentMeta;
use Docuccino\Core\Document\Generator;
use Docuccino\Core\Document\UirDocument;

/**
 * Cheap round-trip coverage for the (currently uncalled) Phase-3 document withers, so their
 * contracts are pinned before the assembler wires them up.
 */
it('swaps the document extension while preserving every other member', function (): void {
    $base = new UirDocument(
        uir: '1.0.0',
        openapi: '3.2.0',
        info: ['title' => 'X', 'version' => '1'],
        paths: [],
        rest: ['x-vendor' => true],
    );

    $extension = new DocumentExtension(document: new DocumentMeta(id: 'doc:default'));
    $updated = $base->withDocumentExtension($extension);

    expect($updated)->not->toBe($base);
    expect($updated->docuccino)->toBe($extension);
    expect($updated->uir)->toBe('1.0.0');
    expect($updated->openapi)->toBe('3.2.0');
    expect($updated->info)->toBe(['title' => 'X', 'version' => '1']);
    expect($updated->rest)->toBe(['x-vendor' => true]);
    expect($updated->toArray()['x-docuccino'])->toBe(['document' => ['id' => 'doc:default']]);
});

it('swaps the DocumentMeta while preserving generator, content and diagnostics', function (): void {
    $generator = new Generator('docuccino/laravel', '1.2.3', '1.0.0');
    $base = new DocumentExtension(
        document: new DocumentMeta(id: 'doc:default'),
        generator: $generator,
        content: ['pages' => []],
    );

    $updated = $base->withDocument(new DocumentMeta(id: 'doc:renamed'));

    expect($updated->document?->id)->toBe('doc:renamed');
    expect($updated->generator)->toBe($generator);
    expect($updated->content)->toBe(['pages' => []]);
});

it('sets the content hash on DocumentMeta without touching id or configHash', function (): void {
    $meta = new DocumentMeta(id: 'doc:default', configHash: 'cfg');

    $hashed = $meta->withContentHash('abc123');

    expect($hashed)->not->toBe($meta);
    expect($hashed->id)->toBe('doc:default');
    expect($hashed->configHash)->toBe('cfg');
    expect($hashed->contentHash)->toBe('abc123');
    expect($meta->contentHash)->toBeNull();
});
