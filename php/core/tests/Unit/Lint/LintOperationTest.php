<?php

declare(strict_types=1);

use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Lint\LintOperation;
use Docuccino\Core\Lint\LintRuleOptions;

/**
 * The walk the completeness lints share. Every HTTP method the spec model knows, and the degradation
 * paths a hand-written or overlaid document can put in front of it.
 */
it('finds an operation under every method a path item can carry', function (string $method): void {
    $operations = LintOperation::all(['paths' => ['/api/thing' => [$method => ['summary' => 'A thing.']]]]);

    expect($operations)->toHaveCount(1)
        ->and($operations[0]->signature)->toBe(strtoupper($method).' /api/thing');
})->with(PathItem::METHODS);

it('skips a path item member that is not an operation', function (): void {
    $operations = LintOperation::all(['paths' => ['/api/thing' => [
        'summary' => 'A path item summary, not an operation.',
        'parameters' => [['name' => 'id']],
        'get' => [],
    ]]]);

    expect($operations)->toHaveCount(1)
        ->and($operations[0]->signature)->toBe('GET /api/thing');
});

it('degrades to nothing on a document with no readable paths', function (mixed $paths): void {
    expect(LintOperation::all($paths === null ? [] : ['paths' => $paths]))->toBe([]);
})->with([
    'absent' => [null],
    'not a map' => ['/api/thing'],
    'path items that are not maps' => [['/api/thing' => 'get']],
    'operations that are not maps' => [['/api/thing' => ['get' => 'ok']]],
]);

it('reads the operationId only when it is a usable string', function (mixed $operationId, ?string $expected): void {
    expect((new LintOperation('GET /x', $operationId === null ? [] : ['operationId' => $operationId]))->operationId())->toBe($expected);
})->with([
    'absent' => [null, null],
    'empty' => ['', null],
    'number' => [42, null],
    'usable' => ['users.index', 'users.index'],
]);

it('reads a source out of the first provenance record that recorded one', function (mixed $extension, ?string $expected): void {
    $source = (new LintOperation('GET /x', $extension === null ? [] : ['x-docuccino' => $extension]))->source();

    expect($source?->file)->toBe($expected);
})->with([
    'no extension member' => [null, null],
    'no provenance' => [['id' => 'op:v1:aaaaaaaaaaaaaaaa'], null],
    'provenance that is not a list' => [['provenance' => 'inference'], null],
    'records with no source' => [['provenance' => [['producer' => 'fallback']]], null],
    'a source with no file' => [['provenance' => [['source' => ['line' => 9]]]], null],
    'the first record carrying one' => [['provenance' => [['producer' => 'fallback'], ['source' => ['file' => 'app/A.php']], ['source' => ['file' => 'app/B.php']]]], 'app/A.php'],
]);

it('silences a subject named in the safelist and nothing else', function (): void {
    $options = new LintRuleOptions(allow: ['GET /api/ping']);

    expect($options->silences('GET /api/ping'))->toBeTrue()
        ->and($options->silences(null, 'GET /api/ping'))->toBeTrue()
        ->and($options->silences('GET /api/pong'))->toBeFalse()
        ->and($options->silences(null, null))->toBeFalse()
        ->and((new LintRuleOptions)->silences('GET /api/ping'))->toBeFalse();
});
