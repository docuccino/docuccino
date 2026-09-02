<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/tools/config-reference-sync.php';

/*
 * The gate that keeps the website's configuration reference honest about the shipped config file.
 * The real pair is checked at the bottom; everything above it proves the two readers on synthetic
 * sources, because a guard that had only ever seen today's files would pass on anything.
 */

it('reads a key whether it ships live or commented out', function (): void {
    // Optional keys ship commented, one short comment each — a reader still has to be able to look
    // one up, so a commented key is as much part of the surface as a live one.
    $config = <<<'PHP'
        <?php

        return [
            'enabled' => true,
            'documents' => [
                'default' => [
                    // The OpenAPI `info` object.
                    'info' => ['title' => 'API Documentation'],
                    // Where #[Webhook] classes live. Absent means the document has none.
                    // 'webhooks' => ['dir' => 'app/Webhooks'],
                ],
            ],
        ];
        PHP;

    expect(config_reference_declared_keys($config))->toBe([
        'documents',
        'documents.*',
        'documents.*.info',
        'documents.*.info.title',
        'documents.*.webhooks',
        'documents.*.webhooks.dir',
        'enabled',
    ]);
});

it('keeps list entries from borrowing the key beside them', function (): void {
    // Two entries in a row: the second must be another `*`, not a repeat of the last key read.
    $config = <<<'PHP'
        <?php

        return [
            'export' => [
                'path' => 'docs/openapi.json',
                // 'targets' => [
                //     ['format' => 'openapi-3.2', 'path' => 'docs/openapi.json'],
                //     ['format' => 'openapi-3.1', 'path' => 'docs/openapi-3.1.yaml'],
                // ],
            ],
        ];
        PHP;

    expect(config_reference_declared_keys($config))->toBe([
        'export',
        'export.path',
        'export.targets',
        'export.targets.*.format',
        'export.targets.*.path',
    ]);
});

it('reads prose as prose, however array-shaped it looks', function (): void {
    // A comment that quotes a shape mid-sentence is documentation, not a declared key.
    $config = <<<'PHP'
        <?php

        return [
            // Written either way — a plain string, or, if the key ever took a bag,
            // ['strategy' => 'default', 'shape' => 'wide'] — because this is a sentence.
            'error_responses' => 'default',
        ];
        PHP;

    expect(config_reference_declared_keys($config))->toBe(['error_responses']);
});

it('reads a key, never a value that happens to sit left of an arrow', function (): void {
    $config = <<<'PHP'
        <?php

        return [
            'lint' => [
                'leakage' => [
                    // 'patterns' => ['sortcode' => 'a bank sort code'],
                    'allow' => ['#/components/schemas/Invoice/properties/status' => 'ignored'],
                ],
            ],
        ];
        PHP;

    expect(config_reference_declared_keys($config))->toBe([
        'lint',
        'lint.leakage',
        'lint.leakage.allow',
        'lint.leakage.patterns',
        'lint.leakage.patterns.sortcode',
    ]);
});

it('reads the sections it was given, and the rest of the page as prose', function (): void {
    $markdown = <<<'MD'
        ### `viewer`

        ```php
        'viewer' => [
            'route' => '/docs/api',
            // 'cdn' => false,
        ],
        ```

        | Key | Default | Effect |
        | --- | --- | --- |
        | `route` | `'/docs/api'` | Base path for the viewer routes. |
        | `cdn` | `false` | Loads the driver's script from a CDN. |

        ## Something else

        | Key | Effect |
        | --- | --- |
        | `invented` | Under no mapped heading, so documenting nothing. |
        MD;

    expect(config_reference_documented_keys($markdown, ['### `viewer`' => 'documents.*.viewer']))->toBe([
        'documents.*.viewer',
        'documents.*.viewer.cdn',
        'documents.*.viewer.route',
    ]);
});

it('reads a block quoting its own key from the parent, and one that does not from the section', function (): void {
    // The page shows each key in context — `'viewer' => [...]` in the viewer section — but an aside
    // quotes one key on its own, and both have to land on the same path.
    $markdown = <<<'MD'
        ### `viewer`

        ```php
        'viewer' => [
            'middleware' => ['web', 'throttle:60,1'],
        ],
        ```

        Override it for a domain-gated app:

        ```php
        'middleware' => ['throttle:60,1'],
        ```
        MD;

    expect(config_reference_documented_keys($markdown, ['### `viewer`' => 'documents.*.viewer']))->toBe([
        'documents.*.viewer',
        'documents.*.viewer.middleware',
    ]);
});

it('reads the table headed Key, and leaves the page\'s other tables alone', function (): void {
    // The page tables integration bags, tag-object fields and credential shapes too. None of them is
    // a config key, and every one of them would read like one.
    $markdown = <<<'MD'
        ### Data leakage

        | Recognized shape | Matches |
        | --- | --- |
        | A PEM private key | `-----BEGIN PRIVATE KEY-----` |

        | Bag | Key | Default | Effect |
        | --- | --- | --- | --- |
        | `sanctum` | `cookie` | `session.cookie` | Stateful cookie name. |

        | Key | Default | Effect |
        | --- | --- | --- |
        | `enabled` | `true` | Turn the pass on/off. |
        MD;

    expect(config_reference_documented_keys($markdown, ['### Data leakage' => 'lint.leakage']))
        ->toBe(['lint.leakage.enabled']);
});

it('drops the paths nobody can look up, and nothing else', function (): void {
    // A wildcard segment names no key; the two exception lists name the rest. Everything beside them
    // stays in the comparison, including the other keys in an integration's own bag.
    expect(config_reference_checkable([
        'documents.*',
        'documents.*.security.schemes',
        'documents.*.security.schemes.bearer.type',
        'documents.*.integrations.sanctum',
        'documents.*.integrations.sanctum.enabled',
        'documents.*.integrations.sanctum.cookie',
        'lint.leakage.patterns',
        'lint.leakage.patterns.sortcode',
    ]))->toBe([
        'documents.*.security.schemes',
        'documents.*.integrations.sanctum',
        'documents.*.integrations.sanctum.cookie',
        'lint.leakage.patterns',
    ]);
});

it('names a key the config ships and the page never mentions', function (): void {
    $config = "<?php\n\nreturn ['cache' => ['store' => null, 'path' => null]];";
    $markdown = "## Cache\n\n```php\n'cache' => ['store' => null],\n```";

    expect(config_reference_problems($config, $markdown, ['## Cache' => 'cache']))
        ->toBe(['undocumented:  cache.path  (in config/docuccino.php, missing from the reference)']);
});

it('names a key the page documents and the config does not have', function (): void {
    // The worse direction of the two: a reader configures something that was never read.
    $config = "<?php\n\nreturn ['cache' => ['store' => null]];";
    $markdown = "## Cache\n\n```php\n'cache' => ['store' => null, 'driver' => 'redis'],\n```";

    expect(config_reference_problems($config, $markdown, ['## Cache' => 'cache']))
        ->toBe(['invented:      cache.driver  (documented, but no such key in config/docuccino.php)']);
});

it('names a section that documents keys under no mapping at all', function (): void {
    // How a whole new section is caught, rather than quietly going unchecked.
    $config = "<?php\n\nreturn ['cache' => ['store' => null]];";
    $markdown = "## Cache\n\n```php\n'cache' => ['store' => null],\n```\n\n".
        "## Telemetry\n\n| Key | Effect |\n| --- | --- |\n| `endpoint` | Where reports go. |";

    expect(config_reference_problems($config, $markdown, ['## Cache' => 'cache']))
        ->toBe(['unmapped:      ## Telemetry  (documents keys; map it in tools/config-reference-sync.php)']);
});

it('names a mapping whose section is gone, and one whose key is gone', function (): void {
    $config = "<?php\n\nreturn ['cache' => ['store' => null]];";
    $markdown = "## Cache\n\n```php\n'cache' => ['store' => null],\n```";

    expect(config_reference_problems($config, $markdown, [
        '## Cache' => 'cache',
        '## Telemetry' => 'telemetry',
    ]))->toBe([
        'missing:       ## Telemetry  (mapped, but the reference has no such section)',
        'stale mapping: ## Telemetry => telemetry  (no such key in config/docuccino.php)',
    ]);
});

it('holds the shipped config and the configuration reference to each other', function (): void {
    $config = (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/config/docuccino.php');
    $reference = (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/reference/configuration.md',
    );

    $problems = config_reference_problems($config, $reference);

    expect($problems)->toBe([], "The configuration reference and config/docuccino.php disagree:\n\n".
        implode("\n", $problems)."\n\nDocument the key in ".
        "website/src/content/docs/laravel/reference/configuration.md, in the section\n".
        "tools/config-reference-sync.php maps to it, or drop it from the config file.\n");
});

it('reads enough of both sides for that comparison to mean something', function (): void {
    // The failure mode of a scan is finding nothing and calling it agreement. Both readers have to
    // come back with the whole surface, the optional keys included.
    $config = (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/config/docuccino.php');
    $reference = (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/reference/configuration.md',
    );

    $declared = config_reference_declared_keys($config);
    $documented = config_reference_documented_keys($reference);
    $expected = [
        'enabled',
        'on_route_error',
        'documents.*.routes.include_vendor',
        'documents.*.webhooks.dir',
        'documents.*.examples.recordings',
        'documents.*.export.mock_faker_key',
        'documents.*.export.targets.*.format',
        'documents.*.viewer.driver',
        'documents.*.representation.errors.components',
        'lint.tags.enabled',
        'engine.project_paths',
        'cache.enabled',
    ];

    expect(count($declared))->toBeGreaterThan(100)
        ->and(count($documented))->toBeGreaterThan(100)
        ->and(count(CONFIG_REFERENCE_SECTIONS))->toBeGreaterThan(20)
        ->and($declared)->toContain(...$expected)
        ->and($documented)->toContain(...$expected);
});

it('keeps every exception and every mapping pointed at something that still exists', function (): void {
    // An exception outlives the key it was written for otherwise, and takes a live subtree out of the
    // comparison on its way.
    $declared = config_reference_declared_keys(
        (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/config/docuccino.php'),
    );

    expect(CONFIG_REFERENCE_KEYED_MAPS)->not->toBeEmpty()
        ->and(CONFIG_REFERENCE_OPAQUE)->not->toBeEmpty()
        ->and(CONFIG_REFERENCE_DOCUMENTED_ONCE)->not->toBeEmpty();

    foreach (CONFIG_REFERENCE_KEYED_MAPS as $map) {
        expect($declared)->toContain($map);
    }

    foreach (CONFIG_REFERENCE_OPAQUE as $opaque) {
        expect($declared)->toContain($opaque);
    }

    foreach (CONFIG_REFERENCE_DOCUMENTED_ONCE as $family) {
        $pattern = '/^'.str_replace('\*', '[^.]+', preg_quote($family, '/')).'$/';
        $covered = array_filter($declared, static fn (string $key): bool => preg_match($pattern, $key) === 1);

        expect($covered)->not->toBeEmpty();
    }
});
