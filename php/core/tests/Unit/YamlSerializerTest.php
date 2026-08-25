<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Emit\YamlSerializer;
use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->yaml = new YamlSerializer;
    $this->json = new CanonicalJsonSerializer;
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

it('writes each empty collection position with the kind OAS requires there', function (string $needle): void {
    $yaml = (new YamlSerializer)->serialize((new Canonicalizer)->canonicalize(emptyCollectionPositions()));

    expect($yaml)->toContain($needle);
})->with([
    // Maps — an empty sequence here is spec-invalid.
    'webhooks is a map' => ['webhooks: {  }'],
    'components.schemas.properties is a map' => ['properties: {  }'],
    'additionalProperties is a schema object' => ['additionalProperties: {  }'],
    'components.examples is a map' => ['examples: {  }'],
    'components.requestBodies is a map' => ['requestBodies: {  }'],
    'components.headers is a map' => ['headers: {  }'],
    'components.securitySchemes is a map' => ['securitySchemes: {  }'],
    'components.links is a map' => ['links: {  }'],
    'components.callbacks is a map' => ['callbacks: {  }'],
    'components.pathItems is a map' => ['pathItems: {  }'],
    'components.responses is a map' => ['responses: {  }'],
    'components.parameters is a map' => ['parameters: {  }'],
    'server variables is a map' => ['variables: {  }'],
    'media type encoding is a map' => ['encoding: {  }'],
    'request body content is a map' => ['content: {  }'],
    // Sequences — these are genuinely arrays and must NOT become maps.
    'tags is a sequence' => ['tags: []'],
    'operation parameters is a sequence' => ['parameters: []'],
    'required is a sequence' => ['required: []'],
    'enum is a sequence' => ['enum: []'],
    'allOf is a sequence' => ['allOf: []'],
    'security requirement scopes is a sequence' => ['apiKey: []'],
]);

it('agrees with the canonical JSON writer on every empty collection it emits', function (): void {
    // The cross-check that would have caught the sequence bug: both writers take the same canonical
    // value, so a map in one must be a map in the other. Before the fix this read 0 maps / 22
    // sequences in YAML against JSON's 16 / 6.
    $canonical = $this->canonicalizer->canonicalize(emptyCollectionPositions());

    $json = $this->json->serialize($canonical);
    $yaml = $this->yaml->serialize($canonical);

    $jsonMaps = preg_match_all('/:\s\{\}/', $json);
    $jsonSequences = preg_match_all('/:\s\[\]/', $json);
    $yamlMaps = preg_match_all('/:\s\{\s+\}$/m', $yaml);
    $yamlSequences = preg_match_all('/:\s\[\]$/m', $yaml);

    // A scan that stopped matching must fail rather than pass silently.
    expect($jsonMaps)->toBeGreaterThan(10)
        ->and($jsonSequences)->toBeGreaterThan(5);

    expect($yamlMaps)->toBe($jsonMaps)
        ->and($yamlSequences)->toBe($jsonSequences);
});

it('renders multi-line strings as literal blocks deterministically', function (): void {
    $value = ['description' => "line one\nline two\nline three"];

    $first = $this->yaml->serialize($value);
    $second = $this->yaml->serialize($value);

    expect($first)->toBe($second);
    expect(Yaml::parse($first)['description'])->toBe("line one\nline two\nline three");
});
