<?php

declare(strict_types=1);

use Docuccino\Core\Contract\Coverage\CoverageLog;
use Docuccino\Core\Contract\Coverage\CoverageMerge;

/*
 * The post-run half of contract coverage: what one process writes, and what the merge makes of N
 * directories of it. Every fixture lives under one directory this file made, and the sweep that takes
 * it away refuses to descend a link — is_dir() answers true for a link to a directory, so a recursive
 * delete that asks it first walks straight out of its own fixture.
 */
beforeEach(function (): void {
    $this->root = coverageFixtureDir('log');
});

afterEach(function (): void {
    removeCoverageFixture($this->root);
});

it('writes a file of its own per process, named after the worker where there is one', function (): void {
    $log = CoverageLog::for($this->root, '7');

    expect($log->append(['op:v1:aaaaaaaaaaaaaaaa', 'op:v1:bbbbbbbbbbbbbbbb']))->toBeTrue()
        ->and($log->file)->toStartWith('7.')
        ->and($log->file)->toEndWith('.ids')
        ->and($log->path())->toBe($this->root.'/'.$log->file)
        ->and(file_get_contents($log->path()))->toBe("op:v1:aaaaaaaaaaaaaaaa\nop:v1:bbbbbbbbbbbbbbbb\n");
});

it('makes a runner-chosen token safe to be a filename, and needs no token at all', function (?string $worker, string $prefix): void {
    // A runner that sets no token is the ordinary single-process case, never an error — and the token
    // it does set is its string, not ours, so it reaches a path only after being made into a name.
    expect(CoverageLog::for($this->root, $worker)->file)->toStartWith($prefix);
})->with([
    'no token at all' => [null, 'main.'],
    'an empty token reads as none' => ['', 'main.'],
    'a plain token is left alone' => ['w12', 'w12.'],
    'a path separator cannot escape the directory' => ['../../etc/passwd', '------etc-passwd.'],
    'a long token is cut to something readable' => [str_repeat('x', 80), str_repeat('x', 32).'.'],
]);

it('never gives two shards on one machine the same filename', function (): void {
    // THE regression this design is most likely to grow: --shard=1/4 and --shard=2/4 both have a worker
    // `1`, so a name that were only the token would have the second silently overwrite the first — and
    // the merge would then report a gap the suite covered perfectly well.
    $shardOne = CoverageLog::for($this->root, '1');
    $shardTwo = CoverageLog::for($this->root, '1');

    $shardOne->append(['op:v1:aaaaaaaaaaaaaaaa']);
    $shardTwo->append(['op:v1:bbbbbbbbbbbbbbbb']);

    expect($shardOne->file)->not->toBe($shardTwo->file)
        ->and(CoverageLog::filesIn($this->root))->toHaveCount(2)
        ->and(CoverageMerge::of([$this->root])->ids)->toBe(['op:v1:aaaaaaaaaaaaaaaa', 'op:v1:bbbbbbbbbbbbbbbb']);
});

it('creates the directory it was pointed at, and creates none for nothing to write', function (): void {
    expect(CoverageLog::for($this->root.'/made/up', '1')->append(['op:v1:aaaaaaaaaaaaaaaa']))->toBeTrue()
        ->and(is_dir($this->root.'/made/up'))->toBeTrue()
        ->and(CoverageLog::for($this->root.'/never', '1')->append([]))->toBeTrue()
        ->and(is_dir($this->root.'/never'))->toBeFalse();
});

it('says so rather than throwing when the directory cannot be made', function (): void {
    file_put_contents($this->root.'/in-the-way', 'not a directory');

    expect(CoverageLog::for($this->root.'/in-the-way', '1')->append(['op:v1:aaaaaaaaaaaaaaaa']))->toBeFalse();
});

it('appends rather than replaces, so a run that dies half way keeps what it reached', function (): void {
    $log = CoverageLog::for($this->root, '1');
    $log->append(['op:v1:aaaaaaaaaaaaaaaa']);
    $log->append(['op:v1:bbbbbbbbbbbbbbbb']);

    expect(CoverageMerge::of([$this->root])->ids)->toBe(['op:v1:aaaaaaaaaaaaaaaa', 'op:v1:bbbbbbbbbbbbbbbb']);
});

it('reports the logs under a directory, descending subdirectories and never a link', function (): void {
    // A directory of downloaded CI artifacts is one nested path per shard, so the scan walks. It does
    // not walk a LINK, which is why the link planted here points inside the fixture and nowhere else.
    mkdir($this->root.'/shard-1');
    mkdir($this->root.'/shard-2');
    CoverageLog::for($this->root.'/shard-1', '1')->append(['op:v1:aaaaaaaaaaaaaaaa']);
    CoverageLog::for($this->root.'/shard-2', '1')->append(['op:v1:bbbbbbbbbbbbbbbb']);
    file_put_contents($this->root.'/shard-1/notes.txt', 'not a log');
    symlink($this->root.'/shard-1', $this->root.'/loop');

    $files = CoverageLog::filesIn($this->root) ?? [];

    expect($files)->toHaveCount(2)
        ->and(implode("\n", $files))->not->toContain('notes.txt')
        ->and(implode("\n", $files))->not->toContain('/loop/')
        ->and(CoverageMerge::of([$this->root])->ids)->toBe(['op:v1:aaaaaaaaaaaaaaaa', 'op:v1:bbbbbbbbbbbbbbbb']);
});

it('reads a path that is not a directory as no directory at all', function (): void {
    file_put_contents($this->root.'/a-file', 'x');
    symlink($this->root, $this->root.'/self');

    expect(CoverageLog::filesIn($this->root.'/nope'))->toBeNull()
        ->and(CoverageLog::filesIn($this->root.'/a-file'))->toBeNull()
        ->and(CoverageLog::filesIn($this->root.'/self'))->toBeNull();
});

it('unions the same ids whatever the worker count and whatever order the directories are given', function (): void {
    // The determinism the whole shape exists for: a union has no first writer, so the answer is a
    // function of what ran and of nothing else — not of scheduling, and not of the merge's argv.
    $single = $this->root.'/single';
    $split = [$this->root.'/w1', $this->root.'/w2', $this->root.'/w3'];

    CoverageLog::for($single)->append(['op:v1:cccccccccccccccc', 'op:v1:aaaaaaaaaaaaaaaa', 'op:v1:bbbbbbbbbbbbbbbb']);
    CoverageLog::for($split[0], '1')->append(['op:v1:bbbbbbbbbbbbbbbb', 'op:v1:aaaaaaaaaaaaaaaa']);
    CoverageLog::for($split[1], '2')->append(['op:v1:cccccccccccccccc']);
    CoverageLog::for($split[2], '3')->append(['op:v1:aaaaaaaaaaaaaaaa']);

    $expected = ['op:v1:aaaaaaaaaaaaaaaa', 'op:v1:bbbbbbbbbbbbbbbb', 'op:v1:cccccccccccccccc'];
    $orders = permutationsOf($split);

    expect($orders)->toHaveCount(6)
        ->and(CoverageMerge::of([$single])->ids)->toBe($expected);

    foreach ($orders as $order) {
        expect(CoverageMerge::of($order)->ids)->toBe($expected);
    }
});

it('counts an id once however many workers met it', function (): void {
    CoverageLog::for($this->root, '1')->append(['op:v1:aaaaaaaaaaaaaaaa', 'op:v1:aaaaaaaaaaaaaaaa']);
    CoverageLog::for($this->root, '2')->append(['op:v1:aaaaaaaaaaaaaaaa']);

    $merge = CoverageMerge::of([$this->root]);

    expect($merge->ids)->toBe(['op:v1:aaaaaaaaaaaaaaaa'])
        ->and($merge->files)->toHaveCount(2)
        ->and($merge->complete())->toBeTrue();
});

it('reads an empty log as a worker that exercised nothing, not as a torn file', function (): void {
    CoverageLog::for($this->root, '1')->append(['op:v1:aaaaaaaaaaaaaaaa']);
    file_put_contents($this->root.'/2.0.deadbeef.ids', '');

    $merge = CoverageMerge::of([$this->root]);

    expect($merge->ids)->toBe(['op:v1:aaaaaaaaaaaaaaaa'])
        ->and($merge->files)->toHaveCount(2)
        ->and($merge->complete())->toBeTrue();
});

it('carries a log a Windows worker wrote with CRLF line endings', function (): void {
    file_put_contents($this->root.'/1.0.deadbeef.ids', "op:v1:aaaaaaaaaaaaaaaa\r\nop:v1:bbbbbbbbbbbbbbbb\r\n");

    expect(CoverageMerge::of([$this->root])->ids)->toBe(['op:v1:aaaaaaaaaaaaaaaa', 'op:v1:bbbbbbbbbbbbbbbb']);
});

it('names a torn log and refuses to be complete, rather than measuring what happened to load', function (string $contents): void {
    // A gate that quietly measured three of four shards is worse than no gate, so a file that does not
    // read back as ids takes the whole merge out of gating rather than contributing what it can.
    CoverageLog::for($this->root, '1')->append(['op:v1:aaaaaaaaaaaaaaaa']);
    file_put_contents($this->root.'/torn.0.deadbeef.ids', $contents);

    $merge = CoverageMerge::of([$this->root]);

    expect($merge->unreadable)->toBe([$this->root.'/torn.0.deadbeef.ids'])
        ->and($merge->complete())->toBeFalse()
        ->and($merge->ids)->toBe(['op:v1:aaaaaaaaaaaaaaaa']);
})->with([
    'a NUL mid-line' => ["op:v1:aaaaaaaaaaaaaaaa\n\x00broken\n"],
    'an escape sequence' => ["\x1b[31mred\n"],
    'a line no id could be' => [str_repeat('x', 300)."\n"],
]);

it('names a directory that is not there and one that holds no log', function (): void {
    CoverageLog::for($this->root.'/full', '1')->append(['op:v1:aaaaaaaaaaaaaaaa']);
    mkdir($this->root.'/blank');

    $merge = CoverageMerge::of([$this->root.'/full', $this->root.'/blank', $this->root.'/absent']);

    expect($merge->ids)->toBe(['op:v1:aaaaaaaaaaaaaaaa'])
        ->and($merge->empty)->toBe([$this->root.'/blank'])
        ->and($merge->missing)->toBe([$this->root.'/absent'])
        ->and($merge->complete())->toBeFalse();
});

it('has nothing to say about a merge nobody asked for', function (): void {
    // Vacuous rather than incomplete: the command always names at least one directory, and an empty
    // list would otherwise have to invent a problem to report about it.
    $merge = CoverageMerge::of([]);

    expect($merge->ids)->toBe([])
        ->and($merge->files)->toBe([])
        ->and($merge->complete())->toBeTrue();
});
