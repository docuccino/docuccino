<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Emitter\UirEmitter;

/**
 * Reverses the key order of every map (associative array) at every depth while leaving
 * list order untouched, so canonicalisation must be what makes two scrambled inputs equal.
 * Contents of `x-*` members other than `x-uir` are left verbatim, mirroring the
 * canonicaliser's passthrough contract (their internal order is intentionally preserved).
 */
function scrambleMaps(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('scrambleMaps', $value);
    }

    $out = [];
    foreach (array_reverse($value, true) as $key => $inner) {
        $isVerbatimExtension = is_string($key) && str_starts_with($key, 'x-') && $key !== 'x-uir';
        $out[$key] = $isVerbatimExtension ? $inner : scrambleMaps($inner);
    }

    return $out;
}

beforeEach(function (): void {
    $this->emitter = new UirEmitter;
    $this->canonicalizer = new Canonicalizer;
});

it('canonicalises scrambled member order to byte-identical output', function (): void {
    $doc = workedExample();
    $scrambled = scrambleMaps($doc);

    expect($this->emitter->emitArray($scrambled))->toBe($this->emitter->emitArray($doc));
});

it('is idempotent: emitting canonical output again yields identical bytes', function (): void {
    $once = $this->emitter->emitArray(workedExample());
    $reparsed = json_decode(trim($once), true);
    $twice = $this->emitter->emitArray($reparsed);

    expect($twice)->toBe($once);
});

it('orders parameters by in-rank then name', function (): void {
    $doc = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [
            '/a/{id}' => [
                'get' => [
                    'parameters' => [
                        ['name' => 'zeta', 'in' => 'query'],
                        ['name' => 'per_page', 'in' => 'query'],
                        ['name' => 'id', 'in' => 'path', 'required' => true],
                        ['name' => 'X-Trace', 'in' => 'header'],
                    ],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);
    $parameters = $canonical['paths']['/a/{id}']['get']['parameters'];
    $order = array_map(static fn (array $p): string => $p['name'], $parameters);

    expect($order)->toBe(['id', 'per_page', 'zeta', 'X-Trace']);
});

it('sorts map keys by code point and orders methods canonically', function (): void {
    $doc = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [
            '/b' => ['post' => ['responses' => []], 'get' => ['responses' => []], 'delete' => ['responses' => []]],
            '/a' => ['get' => ['responses' => []]],
        ],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);

    expect(array_keys($canonical['paths']))->toBe(['/a', '/b']);
    expect(array_keys($canonical['paths']['/b']))->toBe(['get', 'post', 'delete']);
});

it('preserves declaration order while deduplicating enum values', function (): void {
    $schema = ['type' => 'string', 'enum' => ['b', 'a', 'b', 'c', 'a']];
    $doc = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [],
        'components' => ['schemas' => ['S' => $schema]],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);

    expect($canonical['components']['schemas']['S']['enum'])->toBe(['b', 'a', 'c']);
});

it('passes unknown x-* members through verbatim but canonicalises known members', function (): void {
    $doc = [
        'openapi' => '3.2.0',
        'uir' => '1.0.0',
        'info' => ['version' => '1.0.0', 'title' => 'T'],
        'paths' => [],
        'x-vendor' => ['z' => 1, 'a' => 2],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);

    expect(array_keys($canonical))->toBe(['uir', 'openapi', 'info', 'paths', 'x-vendor']);
    expect(array_keys($canonical['info']))->toBe(['title', 'version']);
    expect($canonical['x-vendor'])->toBe(['z' => 1, 'a' => 2]);
});
