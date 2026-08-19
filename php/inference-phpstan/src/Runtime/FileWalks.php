<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use WeakMap;

/**
 * Walks a file's nodes once and replays that walk to every later consumer, so resolving scope over a file —
 * the expensive half of an analysis — is paid once per build rather than once per question. Several consumers
 * ask the same file the same walk: a route's method harvest, then the Query-Builder trace, the inline-rules
 * trace, a pagination trace, and the same again for every other route in the file.
 *
 * The invariant the layer rests on: **a replayed walk and the walk that recorded it are indistinguishable.**
 * Every consumer — the recording one included — is handed the STABILISED scope
 * ({@see RuntimeAdapter::stableScope()}), in `NodeScopeResolver`'s own callback order, so the first walk and
 * the tenth see the same nodes with scopes answering the same `getType()`. That is what makes the layer
 * invisible to output: a recording that is missing, evicted or discarded costs one more live pass and
 * nothing else, so warm equals cold by construction.
 *
 * Not everything may be replayed: an arrow function's lazy fiber scope cannot type expressions once its pass
 * has ended even though other scopes can (docs/design/inference-embedding.md §4b), so closure harvesting
 * calls {@see RuntimeAdapter::processFile()} directly and never comes through here. This class deliberately
 * offers no live pass of its own — a consumer needing one holds the adapter.
 *
 * @internal
 */
final class FileWalks
{
    /**
     * How many recorded nodes to keep. A recorded node retains its parse node, its stabilised scope's share
     * (~2.7 nodes per scope object) and one array slot — measured at ~1.7 KB per node across the fixture
     * app's largest files, so this is a ceiling of roughly 170 MB. The whole fixture app is ~7.5k nodes and
     * a 500-file application extrapolates to ~60k, so only an app several times that ever evicts.
     */
    private const NODE_BUDGET = 100_000;

    /**
     * Recorded walks by normalised file, least-recently-walked first — which is the eviction order.
     *
     * @var array<string, list<array{Node, Scope}>>
     */
    private array $recordings = [];

    private int $recordedNodes = 0;

    /**
     * Files with a live pass in flight. Recording one from inside its own walk would nest
     * `processNodes`, so a re-entrant ask gets a plain pass and records nothing.
     *
     * @var array<string, true>
     */
    private array $inFlight = [];

    /**
     * @param  int  $nodeBudget  overridable so the mechanics can be tested at a budget of a few nodes; the
     *                           build always takes the default, which is why it is a constant and not config
     */
    public function __construct(
        private readonly RuntimeAdapter $adapter,
        private readonly int $nodeBudget = self::NODE_BUDGET,
    ) {}

    /**
     * Drive `$callback(PhpParser\Node, PHPStan\Analyser\Scope)` over every node of a file, from the
     * recording when there is one and from a live pass — recorded as it goes — when there is not.
     */
    public function walk(string $file, callable $callback): void
    {
        $key = $this->adapter->normalize($file);

        $recording = $this->recordings[$key] ?? null;
        if ($recording !== null) {
            // Re-insert so the working set is what survives eviction.
            unset($this->recordings[$key]);
            $this->recordings[$key] = $recording;

            foreach ($recording as [$node, $scope]) {
                $callback($node, $scope);
            }

            return;
        }

        if (isset($this->inFlight[$key])) {
            $this->livePass($file, $callback);

            return;
        }

        $this->inFlight[$key] = true;

        /** @var list<array{Node, Scope}> $recorded */
        $recorded = [];
        try {
            $this->livePass($file, function (Node $node, Scope $scope) use (&$recorded, $callback): void {
                $recorded[] = [$node, $scope];
                $callback($node, $scope);
            });

            // Only a walk that ran to the end is worth keeping: replaying a truncated recording would
            // answer a later consumer with less than a live pass gives it.
            $this->store($key, $recorded);
        } finally {
            unset($this->inFlight[$key]);
        }
    }

    /**
     * One live pass, stabilising each callback scope before anyone sees it. Deduped per scope object because
     * several nodes share one instance, which is what keeps stabilising every node's scope near-free
     * (measured +4.6% over a plain pass, against +14% undeduped). A `WeakMap` rather than an
     * `spl_object_id` array: PHPStan discards scopes as it walks, and a reused object handle would hand a
     * fresh scope the stabilisation of a dead one.
     */
    private function livePass(string $file, callable $callback): void
    {
        /** @var WeakMap<Scope, Scope> $stable */
        $stable = new WeakMap;

        $this->adapter->processFile($file, function (Node $node, Scope $scope) use ($stable, $callback): void {
            $callback($node, $stable[$scope] ??= $this->adapter->stableScope($scope));
        });
    }

    /**
     * @param  list<array{Node, Scope}>  $recorded
     */
    private function store(string $key, array $recorded): void
    {
        $nodes = count($recorded);
        if ($nodes > $this->nodeBudget) {
            return; // one file over the whole budget: evicting everything else for it is not a trade
        }

        $this->recordings[$key] = $recorded;
        $this->recordedNodes += $nodes;

        while ($this->recordedNodes > $this->nodeBudget) {
            $oldest = array_key_first($this->recordings);
            if ($oldest === null || $oldest === $key) {
                break;
            }
            $this->recordedNodes -= count($this->recordings[$oldest]);
            unset($this->recordings[$oldest]);
        }
    }
}
