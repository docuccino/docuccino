<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\OutOfStockException;
use RuntimeException;

/**
 * Service layer for Spike C's Layer 3 (bounded descent) evaluation.
 *
 * Two entry points that are byte-identical in behaviour but differ only in
 * docblocks:
 *   - place()        — NO @throws anywhere in the chain (tests descent).
 *   - placeDeclared() — HAS @throws OutOfStockException (tests docblock trust).
 *
 * Both call reserve() (a second level) which throws RuntimeException with no
 * @throws, so the deepest exception is only recoverable by descending 2 levels.
 * placeLeniently() is place() with that second level caught, and
 * placeUnknown() throws a class no file declares.
 */
class OrderService
{
    /**
     * Case 5: no @throws docblock anywhere. Throws OutOfStockException directly
     * AND calls reserve() (RuntimeException) — 2 levels of undocumented throws.
     */
    public function place(int $productId, int $qty): void
    {
        if ($qty <= 0) {
            throw new OutOfStockException('nothing to place');
        }

        $this->reserve($productId, $qty);
    }

    /**
     * Case 6: same body as place(), but the direct throw IS declared. reserve()
     * is still undeclared, so a naive "trust the docblock" only surfaces
     * OutOfStockException and would miss the deeper RuntimeException.
     *
     * @throws OutOfStockException
     */
    public function placeDeclared(int $productId, int $qty): void
    {
        if ($qty <= 0) {
            throw new OutOfStockException('nothing to place');
        }

        $this->reserve($productId, $qty);
    }

    /**
     * The same body again, with the second level's failure caught here and
     * reported rather than let out — so only OutOfStockException escapes.
     */
    public function placeLeniently(int $productId, int $qty): void
    {
        if ($qty <= 0) {
            throw new OutOfStockException('nothing to place');
        }

        try {
            $this->reserve($productId, $qty);
        } catch (RuntimeException $e) {
            report($e);
        }
    }

    /**
     * Second level. No @throws. Only reachable exception source at depth 2.
     */
    public function reserve(int $productId, int $qty): void
    {
        if ($qty > 100) {
            throw new RuntimeException('cannot reserve more than 100 units');
        }
    }

    /**
     * No @throws, and the class it throws is declared nowhere.
     */
    public function placeUnknown(): void
    {
        throw new \App\Exceptions\NoSuchThrownException('unknown');
    }
}
