<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\CanonicalJsonSerializer;

beforeEach(function (): void {
    $this->serializer = new CanonicalJsonSerializer;
});

it('uses two-space indentation and a trailing newline', function (): void {
    $json = $this->serializer->serialize(['a' => ['b' => 1]]);

    expect($json)->toBe("{\n  \"a\": {\n    \"b\": 1\n  }\n}\n");
});

it('emits empty arrays as [] and empty objects as {}', function (): void {
    expect($this->serializer->serialize(['list' => [], 'object' => new stdClass]))
        ->toBe("{\n  \"list\": [],\n  \"object\": {}\n}\n");
});

it('does not escape forward slashes or unicode', function (): void {
    $json = $this->serializer->serialize(['ref' => '#/components/schemas/Fôo']);

    expect($json)->toContain('#/components/schemas/Fôo');
});

it('preserves the member order it is given', function (): void {
    $json = $this->serializer->serialize(['z' => 1, 'a' => 2, 'm' => 3]);

    expect($json)->toBe("{\n  \"z\": 1,\n  \"a\": 2,\n  \"m\": 3\n}\n");
});

it('formats floats deterministically and round-trips them', function (): void {
    $json = $this->serializer->serialize(['x' => 1.5, 'y' => 0.1]);

    expect($json)->toBe("{\n  \"x\": 1.5,\n  \"y\": 0.1\n}\n");

    $decoded = json_decode(trim($json), true);
    expect($decoded)->toBe(['x' => 1.5, 'y' => 0.1]);
});

it('rejects non-finite floats', function (): void {
    $this->serializer->serialize(['x' => INF]);
})->throws(RuntimeException::class);
