<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;

/**
 * The 3.0 downlevel walk decides what a node is from WHERE it sits, never from how its key is spelled.
 *
 * Every document here spells an application-chosen name — a status code, a component, a header — exactly
 * like a member the walk hands back untouched, and asserts the name changed nothing about how the thing
 * was read. `responses.default` is the one that shipped: the catch-all response, keyed like the schema
 * keyword whose value is user data, so a 3.0 export carried 3.1 dialect underneath it.
 */
describe('a name spelled like a fixed field', function (): void {
    /**
     * A convertible schema and what 3.0 makes of it, so each case below reads as one line.
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    $convertible = static fn (): array => [
        ['type' => ['string', 'null'], 'const' => 'x', 'exclusiveMinimum' => 3],
        ['type' => 'string', 'enum' => ['x'], 'minimum' => 3, 'exclusiveMinimum' => true, 'nullable' => true],
    ];

    it('downlevels the schema beneath it', function (string $case, callable $place, array $pointer) use ($convertible): void {
        [$schema, $expected] = $convertible();

        $result = (new OpenApi30DownlevelEmitter)->emit(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            ...$place($schema),
        ]));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);

        $node = $decoded;
        foreach ($pointer as $token) {
            expect($node)->toHaveKey($token);
            $node = $node[$token];
        }

        expect($node)->toBe($expected);
    })->with([
        // `responses.default` is the catch-all response, and its key is spelled like the schema keyword
        // whose value is user data. Reading the key as a position passed this whole subtree through.
        'the catch-all response' => [
            'the catch-all response',
            fn (array $schema): array => ['paths' => ['/a' => ['get' => ['responses' => ['default' => [
                'description' => 'Unexpected',
                'content' => ['application/json' => ['schema' => $schema]],
            ]]]]]],
            ['paths', '/a', 'get', 'responses', 'default', 'content', 'application/json', 'schema'],
        ],
        'a response component named default' => [
            'a response component named default',
            fn (array $schema): array => ['paths' => [], 'components' => ['responses' => ['default' => [
                'description' => 'Unexpected',
                'content' => ['application/json' => ['schema' => $schema]],
            ]]]],
            ['components', 'responses', 'default', 'content', 'application/json', 'schema'],
        ],
        'a header component named default' => [
            'a header component named default',
            fn (array $schema): array => ['paths' => [], 'components' => ['headers' => ['default' => ['schema' => $schema]]]],
            ['components', 'headers', 'default', 'schema'],
        ],
        'a response header named default' => [
            'a response header named default',
            fn (array $schema): array => ['paths' => ['/a' => ['get' => ['responses' => ['200' => [
                'description' => 'OK',
                'headers' => ['default' => ['schema' => $schema]],
            ]]]]]],
            ['paths', '/a', 'get', 'responses', '200', 'headers', 'default', 'schema'],
        ],
        'a response component named enum' => [
            'a response component named enum',
            fn (array $schema): array => ['paths' => [], 'components' => ['responses' => ['enum' => [
                'description' => 'Enumerated',
                'content' => ['application/json' => ['schema' => $schema]],
            ]]]],
            ['components', 'responses', 'enum', 'content', 'application/json', 'schema'],
        ],
        'a response component named example' => [
            'a response component named example',
            fn (array $schema): array => ['paths' => [], 'components' => ['responses' => ['example' => [
                'description' => 'Exemplary',
                'content' => ['application/json' => ['schema' => $schema]],
            ]]]],
            ['components', 'responses', 'example', 'content', 'application/json', 'schema'],
        ],
        'a parameter component named const' => [
            'a parameter component named const',
            fn (array $schema): array => ['paths' => [], 'components' => ['parameters' => ['const' => [
                'name' => 'pinned', 'in' => 'query', 'schema' => $schema,
            ]]]],
            ['components', 'parameters', 'const', 'schema'],
        ],
        // `components.examples` is a real bucket of Example Objects, not a schema's `examples` keyword —
        // so a `$ref` inside one is a Reference Object, and what it holds is still walked.
        'an example component named examples' => [
            'an example component named examples',
            fn (array $schema): array => ['paths' => [], 'components' => [
                'examples' => ['examples' => ['value' => ['kind' => 'widget']]],
                'headers' => ['h' => ['schema' => $schema]],
            ]],
            ['components', 'headers', 'h', 'schema'],
        ],
    ]);

    it('hands back the user data those names describe, wherever it really is', function (): void {
        $value = ['type' => ['string', 'null'], 'const' => 'x', 'get' => ['responses' => []], 'schema' => ['type' => ['integer', 'null']]];

        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'paths' => ['/a' => ['get' => ['responses' => ['200' => [
                'description' => 'OK',
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object'],
                    'example' => $value,
                    'examples' => ['one' => ['value' => $value]],
                ]],
            ]]]]],
            'components' => ['examples' => ['two' => ['value' => $value]]],
        ]));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        $media = $decoded['paths']['/a']['get']['responses']['200']['content']['application/json'];

        // Untouched at all three positions: a 2020-12 type array inside an example is what the API returns,
        // not a schema this emitter has any business rewriting. Member for member rather than byte for
        // byte, because the canonicalizer sorts an example's members after this emitter has had its say.
        expect($media['example'])->toEqual($value)
            ->and($media['examples']['one']['value'])->toEqual($value)
            ->and($decoded['components']['examples']['two']['value'])->toEqual($value)
            ->and(array_map(static fn ($d): string => $d->code, $result->report->diagnostics))->toBe([]);
    });

    it('reads a $ref in an examples map as a reference, and one in an example value as data', function (): void {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'paths' => ['/a' => ['get' => ['responses' => ['200' => [
                'description' => 'OK',
                'content' => ['application/json' => [
                    'examples' => [
                        'named' => ['$ref' => '#/components/examples/Shared', 'summary' => 'Reworded here'],
                        'inline' => ['value' => ['$ref' => '#/nothing/at/all', 'summary' => 'A payload that talks about refs']],
                    ],
                ],
                ],
            ]]]]],
            'components' => ['examples' => ['Shared' => ['value' => ['kind' => 'widget']]]],
        ]));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        $examples = $decoded['paths']['/a']['get']['responses']['200']['content']['application/json']['examples'];

        expect($examples['named'])->toBe(['$ref' => '#/components/examples/Shared'])
            ->and($examples['inline']['value'])->toBe(['$ref' => '#/nothing/at/all', 'summary' => 'A payload that talks about refs'])
            ->and(array_map(static fn ($d): string => $d->code, $result->report->diagnostics))->toBe(['downlevel.ref-siblings']);
    });

    it('keeps a security requirement named like a component out of the scheme drop', function (): void {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'security' => [['cert' => [], 'apiKey' => []]],
            'paths' => [],
            'components' => [
                // A response component named `security`, whose members are not scheme names.
                'responses' => ['security' => ['description' => 'Not a requirement']],
                'securitySchemes' => [
                    'apiKey' => ['type' => 'apiKey', 'name' => 'X-Api-Key', 'in' => 'header'],
                    'cert' => ['type' => 'mutualTLS'],
                ],
            ],
        ]));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

        expect($decoded['security'])->toBe([['apiKey' => []]])
            ->and($decoded['components']['responses']['security'])->toBe(['description' => 'Not a requirement']);
    });
});
