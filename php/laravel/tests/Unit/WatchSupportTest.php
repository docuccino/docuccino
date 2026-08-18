<?php

declare(strict_types=1);

use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Pipeline\FragmentStore;
use Docuccino\Laravel\Support\AtomicFile;
use Docuccino\Laravel\Tests\Support\WatchFixture;
use Docuccino\Laravel\Watch\ArtisanBuildRunner;
use Docuccino\Laravel\Watch\BuildToken;
use Docuccino\Laravel\Watch\ChangePoller;
use Docuccino\Laravel\Watch\ChangeSummary;
use Docuccino\Laravel\Watch\WatchSet;
use Docuccino\Laravel\Watch\WatchSignal;

/**
 * The small pieces `docuccino:watch` is assembled from: the signal the viewer reads, the token that
 * decides whether there is anything to refresh, the poll loop, the rebuild line, the command line a
 * real session spawns, and the atomic write that keeps a half-exported artifact off disk.
 */

// The signal is also the reload endpoint's on switch, so what counts as "no signal" is load-bearing.
it('publishes, reads back and clears a watch signal', function (): void {
    $path = sys_get_temp_dir().'/docuccino-signal-'.uniqid('', true).'/watch';
    $signal = new WatchSignal($path);
    $token = str_repeat('a', 64);

    expect($signal->token())->toBeNull();

    $signal->publish($token);
    expect($signal->token())->toBe($token);

    $signal->clear();
    expect($signal->token())->toBeNull();

    @unlink(dirname($path).'/.gitignore');
    @rmdir(dirname($path));
});

it('reads anything that is not a digest as no signal at all', function (string $contents): void {
    $path = sys_get_temp_dir().'/docuccino-signal-'.uniqid('', true);
    file_put_contents($path, $contents);

    expect((new WatchSignal($path))->token())->toBeNull();

    @unlink($path);
})->with([
    'empty' => [''],
    'too short' => [str_repeat('a', 63)],
    'not hex' => [str_repeat('z', 64)],
    // The endpoint echoes the token into an event stream, where a newline would be a frame boundary.
    'a forged event frame' => ["\n\nevent: reload\ndata: x"],
]);

it('digests what the build wrote, so an artifact that did not move keeps its token', function (): void {
    $fixture = WatchFixture::make();
    config()->set('docuccino.documents.default.export.path', $fixture->path('docs/openapi.json'));

    $tokens = new BuildToken(app(DocumentBuilder::class), $fixture->root);
    $before = $tokens->of(['default']);

    expect($tokens->of(['default']))->toBe($before);

    file_put_contents($fixture->path('docs/openapi.json'), '{"openapi":"3.2.0"}');
    expect($tokens->of(['default']))->not->toBe($before)->toMatch('/^[0-9a-f]{64}$/');

    $fixture->remove();
});

it('waits until a watched file moves, and gives up when asked to stop', function (): void {
    $fixture = WatchFixture::make();
    $watched = new WatchSet(app(DocumentBuilder::class), new FragmentStore(true, $fixture->path('fragments')), $fixture->root);
    $poller = new ChangePoller($watched, 0.01);
    $roots = [$fixture->path('app')];

    // Rewritten at a different length on the second look: a same-second rewrite of the same size is
    // the one edit the stamp cannot see, and a test must not depend on the clock ticking.
    $polls = 0;
    $changed = $poller->await($roots, function () use (&$polls, $fixture): bool {
        $polls++;
        if ($polls === 2) {
            file_put_contents($fixture->path('app/InvoiceController.php'), '<?php // edited');
        }

        return $polls > 40;
    });

    expect($changed)->toBe([$fixture->path('app/InvoiceController.php')])
        // …and a poller told to stop before anything moves reports nothing rather than a change.
        ->and($poller->await($roots, static fn (): bool => true))->toBe([]);

    $fixture->remove();
});

it('names the files that moved, and counts the rest', function (array $changed, string $expected): void {
    expect(ChangeSummary::of($changed, '/app'))->toBe($expected);
})->with([
    'one' => [['/app/routes/api.php'], 'routes/api.php changed; rebuilding…'],
    'three' => [['/app/a.php', '/app/b.php', '/app/c.php'], 'a.php, b.php, c.php changed; rebuilding…'],
    'more than three' => [
        ['/app/a.php', '/app/b.php', '/app/c.php', '/app/d.php', '/app/e.php'],
        'a.php, b.php, c.php, and 2 more changed; rebuilding…',
    ],
    // A dependency outside the project (a package's own source) has no relative form to print.
    'outside the project' => [['/elsewhere/Vendor.php'], '/elsewhere/Vendor.php changed; rebuilding…'],
]);

it('spawns a fresh artisan export, with every argument escaped', function (?string $document, ?string $memoryLimit, string $expected): void {
    $runner = new ArtisanBuildRunner('/app/artisan', '/usr/bin/php');

    expect($runner->commandLine($document, $memoryLimit))->toBe($expected);
})->with([
    'every document' => [null, null, "'/usr/bin/php' '/app/artisan' 'docuccino:export'"],
    'one document' => ['default', null, "'/usr/bin/php' '/app/artisan' 'docuccino:export' 'default'"],
    'with a memory limit' => ['default', '2G', "'/usr/bin/php' '/app/artisan' 'docuccino:export' 'default' '--memory-limit=2G'"],
    // An unset option arrives as the empty string often enough that it must not become an argument.
    'blank option values' => ['', '', "'/usr/bin/php' '/app/artisan' 'docuccino:export'"],
    'a shell-hostile document key' => ["a'; rm -rf /", null, "'/usr/bin/php' '/app/artisan' 'docuccino:export' 'a'\\''; rm -rf /'"],
]);

it('replaces a file only once the whole of it is written', function (): void {
    $path = sys_get_temp_dir().'/docuccino-atomic-'.uniqid('', true);
    file_put_contents($path, 'old');

    expect(AtomicFile::write($path, 'new contents'))->toBeTrue()
        ->and(file_get_contents($path))->toBe('new contents')
        ->and(glob(dirname($path).'/'.basename($path).'.*.tmp') ?: [])->toBe([]);

    @unlink($path);
});

it('leaves what was there when it cannot write', function (): void {
    $directory = sys_get_temp_dir().'/docuccino-atomic-'.uniqid('', true);

    expect(AtomicFile::write($directory.'/nowhere/deeper.json', '{}'))->toBeFalse()
        ->and(is_file($directory.'/nowhere/deeper.json'))->toBeFalse();
});
