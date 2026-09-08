<?php

declare(strict_types=1);
use Docuccino\Core\Config\ConfigFile;

require_once dirname(__DIR__, 2).'/tools/config-reference-sync.php';

/** The tool's own configuration filename, off the reader that owns it rather than restated here. */
function docuccino_settings_name(): string
{
    return ConfigFile::NAME;
}

it('names the two shipped files the way the product names them', function (): void {
    // The guard is a repo script and cannot be in the package, so it spells the two filenames out.
    // This is the one place they are held to the reader that owns one of them, and to the path the
    // framework publishes the other to.
    expect(CONFIG_REFERENCE_SETTINGS)->toBe(docuccino_settings_name())
        ->and(CONFIG_REFERENCE_FRAMEWORK)->toBe('config/docuccino.php')
        ->and(is_file(dirname(__DIR__, 2).'/php/laravel/'.CONFIG_REFERENCE_FRAMEWORK))->toBeTrue()
        ->and(is_file(dirname(__DIR__, 2).'/php/laravel/config/'.CONFIG_REFERENCE_SETTINGS))->toBeTrue();
});

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

it('reads a yaml block the way it reads a php one, and from the same section', function (): void {
    // The build half of the page is written in YAML and the boot half in PHP, so which reader applies
    // is the fence's language. Both land on the section's own path, and a commented option in either
    // spelling is still a key.
    $markdown = <<<'MD'
        ### `routes`

        ```yaml
        routes:
          include: ['api/*']
          # filter: App\Docs\PublicRoutes
        ```

        ### `viewer`

        ```php
        'viewer' => [
            'route' => '/docs/api',
        ],
        ```
        MD;

    expect(config_reference_documented_keys($markdown, [
        '### `routes`' => 'documents.*.routes',
        '### `viewer`' => 'documents.*.viewer',
    ]))->toBe([
        'documents.*.routes',
        'documents.*.routes.filter',
        'documents.*.routes.include',
        'documents.*.viewer',
        'documents.*.viewer.route',
    ]);
});

it('names a key the config ships and the page never mentions, and says which file ships it', function (): void {
    // One key from each file, so the attribution is proved in both directions rather than assumed
    // from whichever side happened to be read first.
    $config = "<?php\n\nreturn ['cache' => ['store' => null]];";
    $settings = "cache:\n  enabled: false\n";
    $markdown = "## Cache\n\n```yaml\ncache: {}\n```";

    expect(config_reference_problems($config, $settings, $markdown, ['## Cache' => 'cache']))->toBe([
        'undocumented:  cache.enabled  (in docuccino.yaml, missing from the reference)',
        'undocumented:  cache.store  (in config/docuccino.php, missing from the reference)',
    ]);
});

it('names a key the page documents and neither config has', function (): void {
    // The worse direction of the two: a reader configures something that was never read.
    $config = "<?php\n\nreturn ['cache' => ['store' => null]];";
    $settings = "cache:\n  enabled: false\n";
    $markdown = "## Cache\n\n```yaml\ncache:\n  enabled: false\n  store: null\n  driver: 'redis'\n```";

    expect(config_reference_problems($config, $settings, $markdown, ['## Cache' => 'cache']))
        ->toBe(['invented:      cache.driver  (documented, but in neither shipped configuration file)']);
});

it('names a section that documents keys under no mapping at all', function (): void {
    // How a whole new section is caught, rather than quietly going unchecked.
    $config = "<?php\n\nreturn ['cache' => ['store' => null]];";
    $markdown = "## Cache\n\n```php\n'cache' => ['store' => null],\n```\n\n".
        "## Telemetry\n\n| Key | Effect |\n| --- | --- |\n| `endpoint` | Where reports go. |";

    expect(config_reference_problems($config, '', $markdown, ['## Cache' => 'cache']))
        ->toBe(['unmapped:      ## Telemetry  (documents keys; map it in tools/config-reference-sync.php)']);
});

it('names a section whose only keys are in a yaml block as documenting keys', function (): void {
    // The unmapped check reads blocks in both spellings too — a new section written in YAML would
    // otherwise be the one shape that could be documented into a hole.
    $config = "<?php\n\nreturn ['cache' => ['store' => null]];";
    $markdown = "## Cache\n\n```php\n'cache' => ['store' => null],\n```\n\n".
        "## Telemetry\n\n```yaml\ntelemetry:\n  endpoint: 'https://example.com'\n```";

    expect(config_reference_problems($config, '', $markdown, ['## Cache' => 'cache']))
        ->toBe(['unmapped:      ## Telemetry  (documents keys; map it in tools/config-reference-sync.php)']);
});

it('names a mapping whose section is gone, and one whose key is gone', function (): void {
    $config = "<?php\n\nreturn ['cache' => ['store' => null]];";
    $markdown = "## Cache\n\n```php\n'cache' => ['store' => null],\n```";

    expect(config_reference_problems($config, '', $markdown, [
        '## Cache' => 'cache',
        '## Telemetry' => 'telemetry',
    ]))->toBe([
        'missing:       ## Telemetry  (mapped, but the reference has no such section)',
        'stale mapping: ## Telemetry => telemetry  (no such key in either shipped configuration file)',
    ]);
});

it('holds both shipped configuration files and the configuration reference to each other', function (): void {
    $config = (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/config/docuccino.php');
    $settings = (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/config/'.docuccino_settings_name());
    $reference = (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/reference/configuration.md',
    );

    $problems = config_reference_problems($config, $settings, $reference);

    expect($problems)->toBe([], "The configuration reference and the shipped configuration disagree:\n\n".
        implode("\n", $problems)."\n\nDocument the key in ".
        "website/src/content/docs/laravel/reference/configuration.md, in the section\n".
        "tools/config-reference-sync.php maps to it, or drop it from the configuration file.\n");
});

/**
 * The two shipped files are one surface split in two, and the split has to be a PARTITION: a build
 * setting in `config/docuccino.php` is reported and ignored, and a viewer setting in `docuccino.yaml`
 * is not read at all — the build lifts `viewer` off the framework config. Either mistake is a key
 * somebody wrote and nothing honours.
 *
 * Stated as a set comparison over the two files rather than by asking the adapter what it thinks it
 * owns: the framework half is named here literally, so a bug that widened the split cannot make this
 * agree with it. `php/laravel/tests/Unit/ShippedConfigTest.php` holds the same file to
 * `ConfigSplit::FRAMEWORK_KEYS` from the other direction, which is what catches the declaration and
 * the file parting company.
 */
it('splits one surface between the two shipped configuration files, with nothing in both', function (): void {
    $yaml = config_reference_checkable(config_reference_yaml_keys(
        (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/config/'.docuccino_settings_name()),
    ));
    $php = config_reference_checkable(config_reference_declared_keys(
        (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/config/docuccino.php'),
    ));

    // The two paths that legitimately appear in both files are the bags whose MEMBERS the split
    // divides: `documents` is keyed by document on both sides, and `cache` holds `enabled` in one
    // file and `store` in the other. Nothing below either may be in both.
    expect(array_values(array_intersect($php, $yaml)))->toBe(['cache', 'documents']);

    // Per-file floors, because the two files are not the same size: the boot half is twelve keys —
    // the master switch, the cache store, the viewer bag with its seven members, and the two bags
    // that hold them — and the build half is everything else. One shared floor would be nonsense on
    // the small side and a hole on the big one, so the small side is stated in full rather than
    // counted, and the big one carries a floor nothing in its history approaches.
    expect(count($yaml))->toBeGreaterThan(100)
        ->and($php)->toBe([
            'cache',
            'cache.store',
            'documents',
            'documents.*.viewer',
            'documents.*.viewer.cdn',
            'documents.*.viewer.configuration',
            'documents.*.viewer.driver',
            'documents.*.viewer.gate',
            'documents.*.viewer.middleware',
            'documents.*.viewer.route',
            'documents.*.viewer.source',
            'enabled',
        ]);
});

it('reads a commented option out of the YAML the way it reads one out of the PHP', function (): void {
    // The whole reason the YAML reader exists: an optional setting ships commented, and a commented
    // setting nobody documented is exactly the drift this guard is for. The nesting matters as much as
    // the key — a commented child under a shipped `{}` belongs to that key, not beside it.
    $settings = <<<'YAML'
        documents:
          default:
            # The OpenAPI `info` object.
            info:
              title: 'API Documentation'
            # Where #[Webhook] classes live. Absent means the document has none.
            # webhooks: { dir: 'app/Webhooks' }
            integrations: {}
            #   eloquent: { enabled: true }
        YAML;

    expect(config_reference_yaml_keys($settings))->toBe([
        'documents',
        'documents.*',
        'documents.*.info',
        'documents.*.info.title',
        'documents.*.integrations',
        'documents.*.integrations.eloquent',
        'documents.*.integrations.eloquent.enabled',
        'documents.*.webhooks',
        'documents.*.webhooks.dir',
    ]);
});

it('reads no key out of a comment that is only prose', function (): void {
    // A sentence with a colon in it is the one thing that would otherwise parse as a setting.
    $settings = <<<'YAML'
        engine:
          # Declares that this document IS an API version: the one below is `info.version`.
          # Absent means the document is not a version at all.
          mode: 'in-process'
        YAML;

    expect(config_reference_yaml_keys($settings))->toBe(['engine', 'engine.mode']);
});

it('reads enough of every side for that comparison to mean something', function (): void {
    // The failure mode of a scan is finding nothing and calling it agreement. All three readers have
    // to come back with their whole side, the optional keys included.
    $config = (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/config/docuccino.php');
    $settings = (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/config/'.docuccino_settings_name());
    $reference = (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/reference/configuration.md',
    );

    $declared = config_reference_declared_keys($config);
    $settingsKeys = config_reference_yaml_keys($settings);
    $documented = config_reference_documented_keys($reference);

    // Named per file, because a key is now in exactly one of them and a list that pooled the two
    // would pass while one reader returned nothing.
    $boot = ['enabled', 'cache.store', 'documents.*.viewer.driver'];
    $build = [
        'on_route_error',
        'documents.*.routes.include_vendor',
        'documents.*.webhooks.dir',
        'documents.*.examples.recordings',
        'documents.*.export.mock_faker_key',
        'documents.*.export.targets.*.format',
        'documents.*.representation.errors.components',
        'lint.tags.enabled',
        'engine.project_paths',
        'cache.enabled',
    ];

    // Two floors, one per file. THIRTEEN for the boot half is its whole surface as it stands, and it
    // cannot legitimately shrink: a key leaving `config/docuccino.php` means it stopped being read at
    // boot, which is a change to the split rather than to the file. A HUNDRED for the build half is a
    // number the build surface has never been near — it declares well over that — so a reader that
    // lost a nesting level or stopped un-commenting drops through it rather than agreeing quietly.
    expect(count($declared))->toBeGreaterThanOrEqual(13)
        ->and(count($settingsKeys))->toBeGreaterThan(100)
        ->and(count($documented))->toBeGreaterThan(100)
        ->and(count(CONFIG_REFERENCE_SECTIONS))->toBeGreaterThan(20)
        ->and($declared)->toContain(...$boot)
        ->and($settingsKeys)->toContain(...$build)
        ->and($documented)->toContain(...$boot)
        ->and($documented)->toContain(...$build);
});

it('keeps every exception and every mapping pointed at something that still exists', function (): void {
    // An exception outlives the key it was written for otherwise, and takes a live subtree out of the
    // comparison on its way. Read off BOTH files: every exception but `documents` names a build key,
    // so a list checked against the boot half alone would fail on all of them. Unfiltered, because
    // `checkable()` is what the exceptions are FOR — asking it would hide every one of them.
    $declared = [
        ...config_reference_declared_keys(
            (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/config/docuccino.php'),
        ),
        ...config_reference_yaml_keys(
            (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/config/'.docuccino_settings_name()),
        ),
    ];

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
