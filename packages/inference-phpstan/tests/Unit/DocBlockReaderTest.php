<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Metadata\DocBlockReader;

/**
 * The docblock prose reader (in-process, adapter-side): summary/description split, @example, and the
 * degradation branches. read() is built on summary(), so this pins the split's own edge cases (G7).
 */
it('splits leading prose into summary (first paragraph) and description (remainder)', function (): void {
    $read = (new DocBlockReader)->read("/**\n * First summary line.\n *\n * Second paragraph with detail.\n */");

    expect($read['summary'])->toBe('First summary line.')
        ->and($read['description'])->toBe('Second paragraph with detail.');
});

it('joins paragraphs beyond the first into the description', function (): void {
    $read = (new DocBlockReader)->read("/**\n * Summary.\n *\n * Second para.\n *\n * Third para.\n */");

    expect($read['summary'])->toBe('Summary.')
        ->and($read['description'])->toBe("Second para.\n\nThird para.");
});

it('leaves the description null for a single-paragraph docblock', function (): void {
    $read = (new DocBlockReader)->read("/**\n * Only one paragraph here.\n */");

    expect($read['summary'])->toBe('Only one paragraph here.')
        ->and($read['description'])->toBeNull();
});

it('returns both null for an empty or absent docblock', function (?string $doc): void {
    expect((new DocBlockReader)->read($doc))->toBe(['summary' => null, 'description' => null]);
})->with([
    'empty docblock' => ['/** */'],
    'null' => [null],
]);

it('reads the first non-empty @example and the leading prose via summary()', function (): void {
    $reader = new DocBlockReader;
    $doc = "/**\n * A summary.\n *\n * @example {\"id\": 1}\n */";

    expect($reader->summary($doc))->toBe('A summary.')
        ->and($reader->example($doc))->toBe('{"id": 1}');
});
