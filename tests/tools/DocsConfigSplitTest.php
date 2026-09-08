<?php

declare(strict_types=1);
use Docuccino\Core\Config\ConfigFile;
use Docuccino\Laravel\Config\ConfigSplit;
use Docuccino\Laravel\Config\DeclaredSettings;

require_once dirname(__DIR__, 2).'/tools/docs-config-split.php';

/*
 * The gate that keeps the whole documentation site honest about which configuration file a setting
 * lives in. The real site is checked at the bottom; everything above proves the reader on synthetic
 * pages, because a guard that had only ever seen today's pages would pass on anything.
 */

function docs_config_shipped_php(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/'.CONFIG_REFERENCE_FRAMEWORK);
}

function docs_config_shipped_yaml(): string
{
    return (string) file_get_contents(DeclaredSettings::path());
}

/** @param  array<string, string>  $pages */
function docs_config_problems_for(array $pages): array
{
    return docs_config_split_problems($pages, docs_config_shipped_php(), docs_config_shipped_yaml());
}

it('reads the names off the shipped files at the three levels a page quotes from', function (): void {
    $names = docs_config_setting_names([
        'enabled',
        'cache',
        'cache.store',
        'documents',
        'documents.*',
        'documents.*.error_responses',
        'documents.*.viewer',
        'documents.*.viewer.gate',
        // Deeper than a page quotes from, and a word an application's own arrays are full of.
        'documents.*.export.path',
    ]);

    expect($names)->toBe(['cache', 'documents', 'enabled', 'error_responses', 'gate', 'viewer']);
});

it('matches a snippet quoted from any depth against the path it belongs to', function (string $key, bool $expected): void {
    expect(docs_config_is_tail_of($key, 'documents.*.viewer.gate'))->toBe($expected);
})->with([
    'the leaf alone, as a viewer block quotes it' => ['gate', true],
    'the leaf under its bag' => ['viewer.gate', true],
    'a named document rather than the wildcard' => ['default.viewer.gate', true],
    'the whole path' => ['documents.*.viewer.gate', true],
    'the same leaf under another bag' => ['routes.gate', false],
    // The reason the last segment is matched literally: a build setting sits exactly where a
    // document's name does, so a wildcard tail would admit every one-segment key there is.
    'a build setting where a document name goes' => ['error_responses', false],
    'a different leaf' => ['driver', false],
    'longer than the path itself' => ['documents.*.viewer.gate.ability', false],
]);

it('refuses a build setting shown as PHP, wherever the block was quoted from', function (string $block, string $key): void {
    $problems = docs_config_problems_for(['a-page.mdx' => "```php\n".$block."\n```"]);

    expect($problems)->not->toBeEmpty()
        ->and(array_filter($problems, static fn (string $p): bool => str_contains($p, 'build setting as PHP:  '.$key.'  ')))->not->toBeEmpty()
        ->and($problems[0])->toContain('a-page.mdx')
        ->and($problems[0])->toContain(ConfigFile::NAME);
})->with([
    'a document-level setting' => ["'error_responses' => 'default',", 'error_responses'],
    'a top-level setting' => ["'extensions' => [\n    \\App\\Docs\\MoneyToSchema::class,\n],", 'extensions'],
    'the build half of a bag the two files share' => ["'cache' => ['enabled' => true],", 'cache.enabled'],
    'a whole document, viewer aside' => ["'documents' => [\n    'public' => ['routes' => ['include' => ['api/*']]],\n],", 'documents.*.routes'],
]);

it('admits the framework config it ships, and every viewer snippet quoted out of it', function (string $block): void {
    expect(docs_config_problems_for(['a-page.mdx' => "```php\n".$block."\n```"]))->toBe([]);
})->with([
    'the shipped file itself' => [docs_config_shipped_php()],
    'one viewer key' => ["'source' => 'artifact',"],
    'a viewer bag under its document' => ["'documents' => [\n    'admin' => ['viewer' => ['route' => '/docs/admin', 'gate' => 'viewAdminDocs']],\n],"],
    'the master switch' => ["'enabled' => env('DOCUCCINO_ENABLED', true),"],
    'the store a viewer request reads' => ["'cache' => ['store' => 'redis'],"],
]);

it('leaves an array that is the reader\'s own code alone', function (string $block): void {
    expect(docs_config_problems_for(['a-page.mdx' => "```php\n".$block."\n```"]))->toBe([]);
})->with([
    'a form request\'s rules' => ["public function rules(): array\n{\n    return ['reference' => 'required|string', 'billing.email' => 'email'];\n}"],
    'a JSON Schema an extension returns' => ["return SchemaResult::of([\n    'type' => 'object',\n    'properties' => ['amount' => ['type' => 'integer']],\n    'required' => ['amount'],\n]);"],
    'a header an extension contributes' => ["\$draft->headers(['X-Request-Id' => ['schema' => ['type' => 'string', 'format' => 'uuid']]]);"],
]);

it('reads a yaml block as nobody\'s PHP, however its keys are spelled', function (): void {
    // The whole point of the migration: the same settings in a `yaml` fence are right, and this guard
    // has nothing to say about them.
    expect(docs_config_problems_for([
        'a-page.mdx' => "```yaml\ndocuments:\n  default:\n    error_responses: 'default'\n```",
    ]))->toBe([]);
});

it('counts the blocks and pages it reached, so a reader that stopped matching cannot pass', function (): void {
    $reach = docs_config_split_reach(
        ['a-page.mdx' => "```php\n'source' => 'artifact',\n```\n\n```php\npublic function rules(): array { return ['a' => 'b']; }\n```"],
        docs_config_shipped_php(),
        docs_config_shipped_yaml(),
    );

    expect($reach)->toBe(['blocks' => 1, 'pages' => 1]);
});

it('agrees with the product about what the framework config still owns', function (): void {
    // Two statements of one surface: this guard derives it from the shipped bytes, and `ConfigSplit`
    // hard-codes the prefixes the BUILD checks a leftover key against. A tightened boot surface that
    // moved only one of them would leave a page admitted here and reported by a build.
    $boot = config_reference_declared_keys(docs_config_shipped_php());

    foreach ($boot as $path) {
        if (in_array($path, ['documents', 'documents.*', 'cache'], true)) {
            // The two bags that straddle the split, and their document keys: not settings themselves.
            continue;
        }

        $owned = false;
        foreach (ConfigSplit::FRAMEWORK_KEYS as $framework) {
            $owned = $owned || $path === $framework || str_starts_with($path, $framework.'.');
        }

        expect($owned)->toBeTrue("the shipped framework config declares {$path}, which ConfigSplit::FRAMEWORK_KEYS does not own");
    }
});

it('shows every configuration snippet on the site in the file that reads it', function (): void {
    $pages = docs_config_split_pages(dirname(__DIR__, 2).'/'.DOCS_CONFIG_SPLIT_ROOT);
    $reach = docs_config_split_reach($pages, docs_config_shipped_php(), docs_config_shipped_yaml());

    // A plausible minimum beside the real assertion: the viewer is configured in PHP and several
    // pages show it, so a reader that stopped recognizing a config block would report nothing wrong
    // while checking nothing at all.
    expect($reach['blocks'])->toBeGreaterThanOrEqual(8)
        ->and($reach['pages'])->toBeGreaterThanOrEqual(4);

    expect(docs_config_split_problems($pages, docs_config_shipped_php(), docs_config_shipped_yaml()))->toBe([]);
});
