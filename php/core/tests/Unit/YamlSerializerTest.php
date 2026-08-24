<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Emit\YamlSerializer;
use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->yaml = new YamlSerializer;
    $this->canonicalizer = new Canonicalizer;
});

it('serialises identical canonical input to byte-identical YAML across runs', function (): void {
    $canonical = $this->canonicalizer->canonicalize(workedExample());

    expect($this->yaml->serialize($canonical))->toBe($this->yaml->serialize($canonical));
});

it('preserves canonical member order rather than re-sorting', function (): void {
    // Canonicalisation fixes order (uir before openapi before info); YAML must not disturb it.
    $canonical = $this->canonicalizer->canonicalize(workedExample());

    $yaml = $this->yaml->serialize($canonical);

    $uirPos = strpos($yaml, 'uir:');
    $openapiPos = strpos($yaml, 'openapi:');
    $infoPos = strpos($yaml, 'info:');

    expect($uirPos)->toBeLessThan($openapiPos);
    expect($openapiPos)->toBeLessThan($infoPos);
});

it('uses block style rather than inline braces for populated maps', function (): void {
    $yaml = $this->yaml->serialize($this->canonicalizer->canonicalize([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
    ]));

    expect($yaml)->toContain("info:\n");
    expect($yaml)->toContain('  title: API');
    expect($yaml)->not->toContain('{title:');
});

// The map-versus-sequence distinction is carried ONLY by the PHP type: stdClass means map, array
// means sequence. `Yaml::parse()` decodes both to `[]`, and so does an associative `json_decode`, so
// a round-trip assertion through either cannot tell `paths: {  }` from `paths: []` — it held green
// while every empty map in the document was written as a spec-invalid sequence. Only the bytes can
// prove this, so these assert bytes.

it('writes an empty stdClass as a map and an empty array as a sequence', function (): void {
    $yaml = $this->yaml->serialize(['emptyMap' => new stdClass, 'emptySequence' => []]);

    expect($yaml)->toBe("emptyMap: {  }\nemptySequence: []\n");
});

it('round-tripping cannot tell a map from a sequence, which is why the bytes are asserted', function (): void {
    // Pinned so the blind spot stays documented by a failing test if Symfony ever distinguishes them.
    $map = $this->yaml->serialize(['x' => new stdClass]);
    $sequence = $this->yaml->serialize(['x' => []]);

    expect($map)->not->toBe($sequence);
    expect(Yaml::parse($map))->toEqual(Yaml::parse($sequence));
});

it('writes the paths of a routeless document as a map', function (): void {
    // `paths` is an OAS Map; a document with no routes still owes a map, not `paths: []`.
    $yaml = $this->yaml->serialize($this->canonicalizer->canonicalize([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [],
        'security' => [],
    ]));

    expect($yaml)->toContain("paths: {  }\n")
        ->and($yaml)->toContain("security: []\n");
});

it('renders multi-line strings as literal blocks deterministically', function (): void {
    $value = ['description' => "line one\nline two\nline three"];

    $first = $this->yaml->serialize($value);
    $second = $this->yaml->serialize($value);

    expect($first)->toBe($second);
    expect(Yaml::parse($first)['description'])->toBe("line one\nline two\nline three");
});
