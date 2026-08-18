<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/tools/generator-version.php';

/*
 * The release workflow's bump of `DocuccinoServiceProvider::VERSION`. Providers are synthesized in a
 * temp tree and the real one is never written to — this repository's own constant is only ever moved
 * by a release run.
 */

/** The declaration as the provider really spells it, so the pattern is proved against the shipped shape. */
function generatorVersionProvider(string $version): string
{
    return "<?php\n\nfinal class DocuccinoServiceProvider\n{\n    public const string VERSION = '".$version."';\n}\n";
}

/**
 * Run the tool against a throwaway repository root holding $source, with both streams captured.
 *
 * @return array{code: int, out: string, path: string}
 */
function generatorVersionRun(string $argument, string $source, string $label): array
{
    $root = sys_get_temp_dir().'/docuccino-generator-version-'.$label.'-'.uniqid();
    $path = $root.'/'.GENERATOR_VERSION_PATH;

    if ($source !== '') {
        mkdir(dirname($path), recursive: true);
        file_put_contents($path, $source);
    } else {
        mkdir($root, recursive: true);
    }

    // One stream for both: what a reader gets is the point, not which fd it arrived on.
    $stream = fopen('php://memory', 'r+');
    expect($stream)->not->toBeFalse();

    $code = generator_version_main($argument, $root, $stream, $stream);
    rewind($stream);
    $out = (string) stream_get_contents($stream);
    fclose($stream);

    return ['code' => $code, 'out' => $out, 'path' => $path];
}

it('rewrites the constant to the version the release names', function (string $argument): void {
    // `tools/changelog.php --print-version` prints `v1.2.3`; the constant holds `1.2.3`.
    $run = generatorVersionRun($argument, generatorVersionProvider('0.5.1'), 'bump');

    expect($run['code'])->toBe(0)
        ->and(file_get_contents($run['path']))->toBe(generatorVersionProvider('1.2.3'));
})->with([
    'tagged' => ['v1.2.3'],
    'bare' => ['1.2.3'],
]);

it('writes nothing when the constant already names the version', function (): void {
    // The state between merging a release and pushing its tag, and every workflow re-run: no diff,
    // so the release workflow's pending check goes on seeing nothing to release.
    $run = generatorVersionRun('v1.2.3', generatorVersionProvider('1.2.3'), 'idempotent');

    expect($run['code'])->toBe(0)
        ->and(file_get_contents($run['path']))->toBe(generatorVersionProvider('1.2.3'))
        ->and($run['out'])->toContain('unchanged, already 1.2.3');
});

it('fails loudly when the declaration is not there exactly once', function (string $label, string $source): void {
    $run = generatorVersionRun('v1.2.3', $source, $label);

    expect($run['code'])->toBe(1)
        ->and(file_get_contents($run['path']))->toBe($source)
        ->and($run['out'])->toContain('expected exactly one')
        ->and($run['out'])->toContain('tools/generator-version.php');
})->with([
    // Renaming, reformatting or copying the declaration is what a refactor of the provider does.
    'renamed' => ['renamed', "<?php\n\npublic const string RELEASE = '0.5.1';\n"],
    'reformatted' => ['reformatted', "<?php\n\npublic const string VERSION='0.5.1';\n"],
    'absent' => ['absent', "<?php\n\nfinal class DocuccinoServiceProvider {}\n"],
    'duplicated' => ['duplicated', "<?php\n\nconst string VERSION = '0.5.1';\nconst string VERSION = '0.5.1';\n"],
]);

it('rejects an argument that is not a release version', function (string $label, string $argument): void {
    $run = generatorVersionRun($argument, generatorVersionProvider('0.5.1'), 'argument-'.$label);

    expect($run['code'])->toBe(1)
        ->and(file_get_contents($run['path']))->toBe(generatorVersionProvider('0.5.1'))
        ->and($run['out'])->toContain('is not a release version');
})->with([
    // The empty string is what the workflow would pass if it ever ran this with nothing pending.
    'nothing' => ['nothing', ''],
    'a branch' => ['branch', 'main'],
    'two parts' => ['two-parts', 'v1.2'],
    'a suffix' => ['suffix', 'v1.2.3-beta'],
]);

it('reports a provider that is not there', function (): void {
    $run = generatorVersionRun('v1.2.3', '', 'missing');

    expect($run['code'])->toBe(1)
        ->and($run['out'])->toContain('is not there');
});

it('leaves this repository its own constant', function (): void {
    // The shipped provider must carry exactly one declaration in the shape the workflow rewrites.
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.GENERATOR_VERSION_PATH);

    expect(generator_version_rewrite($source, '9.9.9'))->not->toBeNull()
        ->and(generator_version_rewrite($source, '9.9.9'))->toContain("VERSION = '9.9.9';");
});
