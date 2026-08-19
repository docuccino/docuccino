<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;

/**
 * In-process mechanics cover for {@see FileWalks}: how many live passes it spends, what a replay hands over
 * and in what order, and every path that declines to serve one. Whether a REAL replayed walk answers types
 * the way the live pass that recorded it did is the fixture group's job (`ReplayParityTest`).
 */

/**
 * An adapter emitting a scripted node/scope sequence per file, counting its passes into `$passes`.
 * `stableScope()` hands back a distinct object per raw scope, so a test can see that consumers were given
 * the stabilised scope and not the raw one — which is the whole reason a replay can answer at all.
 *
 * @param  array<string, list<array{Node, Scope}>>  $script  file → the pairs its pass emits
 * @param  array<string, int>  $passes  live passes per file, so replays are observable
 */
function scriptedWalkAdapter(array $script, array &$passes): RuntimeAdapter
{
    return new class($script, $passes) implements RuntimeAdapter
    {
        /** @var array<int, Scope> */
        private array $stabilised = [];

        /**
         * @param  array<string, list<array{Node, Scope}>>  $script
         * @param  array<string, int>  $passes
         */
        public function __construct(private readonly array $script, private array &$passes) {}

        public function boot(): void {}

        public function prime(array $files): void {}

        public function processFile(string $file, callable $callback): void
        {
            $this->passes[$file] = ($this->passes[$file] ?? 0) + 1;

            foreach ($this->script[$file] ?? [] as [$node, $scope]) {
                $callback($node, $scope);
            }
        }

        public function normalize(string $file): string
        {
            return $file;
        }

        public function stableScope(Scope $scope): Scope
        {
            // One stand-in per raw scope, stable across calls — a real toMutatingScope() is likewise a
            // function of the scope it is asked about.
            return $this->stabilised[spl_object_id($scope)] ??= clone $scope;
        }

        public function reflectionProvider(): ReflectionProvider
        {
            throw new RuntimeException('not used in this unit');
        }
    };
}

/**
 * Each walked pair as `[node class, probe name, scope object id]` — enough to compare two walks node for
 * node, scope for scope, in order.
 *
 * @param  list<array{Node, Scope}>  $pairs
 * @return list<array{string, string, int}>
 */
function walkShape(array $pairs): array
{
    return array_map(
        static fn (array $pair): array => [
            $pair[0]::class,
            (string) $pair[0]->getAttribute('probe'),
            spl_object_id($pair[1]),
        ],
        $pairs,
    );
}

/**
 * @return list<array{Node, Scope}>
 */
function collectWalk(FileWalks $walks, string $file): array
{
    $seen = [];
    $walks->walk($file, static function (Node $node, Scope $scope) use (&$seen): void {
        $seen[] = [$node, $scope];
    });

    return $seen;
}

/** A node a walk-shape comparison can tell apart from its neighbours. */
function probeNode(string $name): Node\Expr\Variable
{
    $node = new Node\Expr\Variable($name);
    $node->setAttribute('probe', $name);

    return $node;
}

it('replays a recorded walk verbatim, on one live pass', function (): void {
    $scopeA = $this->createStub(Scope::class);
    $scopeB = $this->createStub(Scope::class);
    $passes = [];

    // The first two nodes share one scope instance, which is the shape the dedupe exists for.
    $adapter = scriptedWalkAdapter(['/a.php' => [
        [probeNode('one'), $scopeA],
        [probeNode('two'), $scopeA],
        [probeNode('three'), $scopeB],
    ]], $passes);
    $walks = new FileWalks($adapter);

    $first = collectWalk($walks, '/a.php');
    $second = collectWalk($walks, '/a.php');
    $third = collectWalk($walks, '/a.php');

    // Same nodes, same scope objects, same order — a replay is indistinguishable from the walk that
    // recorded it, which is what makes the layer invisible to the emitted document.
    expect($first)->toHaveCount(3)
        ->and(walkShape($second))->toBe(walkShape($first))
        ->and(walkShape($third))->toBe(walkShape($first))
        ->and($passes)->toBe(['/a.php' => 1]);

    // And what was recorded is the STABILISED scope, not the one the pass handed out.
    expect($first[0][1])->not->toBe($scopeA)
        ->and($first[0][1])->toBe($adapter->stableScope($scopeA))
        ->and($first[1][1])->toBe($first[0][1])
        ->and($first[2][1])->toBe($adapter->stableScope($scopeB));
});

it('records each file separately', function (): void {
    $scope = $this->createStub(Scope::class);
    $passes = [];
    $adapter = scriptedWalkAdapter([
        '/a.php' => [[probeNode('a'), $scope]],
        '/b.php' => [[probeNode('b'), $scope]],
    ], $passes);
    $walks = new FileWalks($adapter);

    foreach (['/a.php', '/b.php', '/a.php', '/b.php'] as $file) {
        collectWalk($walks, $file);
    }

    expect($passes)->toBe(['/a.php' => 1, '/b.php' => 1]);
});

it('replays a file whose pass emitted nothing', function (): void {
    // Nothing harvested is a real answer — an interface, a file of constants — and re-walking to hear it
    // again is exactly the cost this layer exists to stop paying.
    $passes = [];
    $walks = new FileWalks(scriptedWalkAdapter([], $passes));

    expect(collectWalk($walks, '/empty.php'))->toBe([])
        ->and(collectWalk($walks, '/empty.php'))->toBe([])
        ->and($passes)->toBe(['/empty.php' => 1]);
});

it('records nothing for a pass that blew up, so the next ask gets a live one', function (): void {
    // A truncated recording would answer a later consumer with less than a live pass gives it — the one
    // way this layer could change what a build says. Discarding is the honest fallback.
    $scope = $this->createStub(Scope::class);
    $passes = [];
    $walks = new FileWalks(scriptedWalkAdapter(['/a.php' => [[probeNode('a'), $scope]]], $passes));

    $attempt = static function () use ($walks): void {
        $walks->walk('/a.php', static function (): void {
            throw new RuntimeException('visitor blew up');
        });
    };

    expect($attempt)->toThrow(RuntimeException::class, 'visitor blew up')
        ->and($attempt)->toThrow(RuntimeException::class)
        ->and($passes)->toBe(['/a.php' => 2]);

    // Once a walk completes, the recording stands.
    collectWalk($walks, '/a.php');
    collectWalk($walks, '/a.php');
    expect($passes)->toBe(['/a.php' => 3]);
});

it('serves a re-entrant ask for the file being walked with a plain pass', function (): void {
    // Recording a file from inside its own walk would nest processNodes — the trap the whole trace design
    // avoids. The inner ask gets a live pass and records nothing; the outer recording still stands.
    $scope = $this->createStub(Scope::class);
    $passes = [];
    $adapter = scriptedWalkAdapter(['/a.php' => [[probeNode('a'), $scope]]], $passes);
    $walks = new FileWalks($adapter);

    $inner = [];
    $walks->walk('/a.php', function () use ($walks, &$inner): void {
        $inner = collectWalk($walks, '/a.php');
    });

    expect($passes)->toBe(['/a.php' => 2])
        ->and($inner)->toHaveCount(1)
        // The inner pass stabilises too, so a re-entrant consumer sees exactly what a replay would.
        ->and($inner[0][1])->toBe($adapter->stableScope($scope));

    collectWalk($walks, '/a.php');
    expect($passes)->toBe(['/a.php' => 2]);
});

it('declines to record a file bigger than the whole node budget', function (): void {
    // Nothing is evicted for a file that could not fit anyway; it just costs a live pass every time.
    $scope = $this->createStub(Scope::class);
    $passes = [];
    $adapter = scriptedWalkAdapter([
        '/small.php' => [[probeNode('a'), $scope]],
        '/huge.php' => [[probeNode('b'), $scope], [probeNode('c'), $scope], [probeNode('d'), $scope]],
    ], $passes);
    $walks = new FileWalks($adapter, nodeBudget: 2);

    collectWalk($walks, '/small.php');
    collectWalk($walks, '/huge.php');
    collectWalk($walks, '/huge.php');
    collectWalk($walks, '/small.php');

    expect($passes)->toBe(['/small.php' => 1, '/huge.php' => 2]);
});

it('evicts the least recently walked file once the node budget is full', function (): void {
    // Eviction is a cost, never an answer: an evicted file is walked live again and replies identically.
    $scope = $this->createStub(Scope::class);
    $passes = [];
    $adapter = scriptedWalkAdapter([
        '/a.php' => [[probeNode('a1'), $scope], [probeNode('a2'), $scope]],
        '/b.php' => [[probeNode('b1'), $scope], [probeNode('b2'), $scope]],
        '/c.php' => [[probeNode('c1'), $scope], [probeNode('c2'), $scope]],
    ], $passes);
    $walks = new FileWalks($adapter, nodeBudget: 4);

    $liveA = collectWalk($walks, '/a.php');
    collectWalk($walks, '/b.php');            // the two of them fill the budget exactly
    collectWalk($walks, '/a.php');            // a replay, which also makes /a.php the recently used one
    collectWalk($walks, '/c.php');            // over budget ⇒ /b.php, the least recently walked, goes
    $replayedA = collectWalk($walks, '/a.php');
    collectWalk($walks, '/b.php');            // evicted, so this one is live again

    expect($passes)->toBe(['/a.php' => 1, '/b.php' => 2, '/c.php' => 1])
        // The survivor's replay is still the recording, unaffected by what was evicted around it.
        ->and(walkShape($replayedA))->toBe(walkShape($liveA));
});
