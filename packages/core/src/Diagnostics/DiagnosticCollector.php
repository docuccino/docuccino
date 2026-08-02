<?php

declare(strict_types=1);

namespace Docuccino\Core\Diagnostics;

use Docuccino\Core\Extensions\Contracts\DocumentTransformer;

/**
 * A small mutable sink for diagnostics raised outside the per-route pipeline — notably by
 * {@see DocumentTransformer}s, which run whole-document after
 * assembly and would otherwise have nowhere to report (the CLI is the primary diagnostics channel).
 * Held (by reference) on a readonly context so a transformer can report without the context itself
 * becoming mutable.
 */
final class DiagnosticCollector
{
    /**
     * @var list<Diagnostic>
     */
    private array $diagnostics = [];

    public function add(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    /**
     * @return list<Diagnostic>
     */
    public function all(): array
    {
        return $this->diagnostics;
    }
}
