<?php

declare(strict_types=1);

namespace Docuccino\SpikeB;

/**
 * Mutable accumulator the QueryBuilder visitor writes into. Kept separate from
 * the visitor so it can be inspected/serialised after the trace (mirrors the
 * real engine handing back an ActionAnalysis-shaped result).
 */
final class TraceResult
{
    /** @var list<ConstValue> */
    public array $allowedFilters = [];

    /** @var list<ConstValue> */
    public array $allowedSorts = [];

    /** @var list<ConstValue> */
    public array $defaultSort = [];

    /**
     * Every paginating-terminal hit, in discovery order. Each:
     *   ['terminal' => string, 'perPage' => int|null, 'receiver' => string, 'loc' => array]
     *
     * @var list<array{terminal: string, perPage: int|null, receiver: string, loc: array{file:string,line:int,pos:int}}>
     */
    public array $terminalHits = [];

    /**
     * Ordered descent chain (the hops the Tracer actually took), plus
     * matched-but-not-descended vendor terminal leaves.
     *
     * @var list<array{depth:int, class:string, method:string, via:?string, vendor:bool, note:?string}>
     */
    public array $chain = [];

    public function paginates(): bool
    {
        return $this->terminalHits !== [];
    }

    public function recoveredPerPage(): ?int
    {
        foreach ($this->terminalHits as $hit) {
            if ($hit['perPage'] !== null) {
                return $hit['perPage'];
            }
        }

        return null;
    }
}
