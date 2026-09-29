<?php

namespace App\Http;

use Docuccino\Attributes\OperationId;

/**
 * Real-engine fixture: closure route actions whose declaration begins before their `function`/`fn`
 * keyword — an attribute above it, or `static` on a line of its own — and closures sharing a line.
 * Reflection places a closure on its keyword's line, the parser on its attribute's or modifier's, so these
 * are the shapes a lookup by the parser's start line misses or mistakes for a neighbour. Nothing boots;
 * the tests reflect these closures and analyse this file.
 */
final class ClosureRoutes
{
    /**
     * @return array<string, \Closure>
     */
    public static function all(): array
    {
        return [
            'attributed' => #[OperationId('attributed')]
                function (): array {
                    return ['kind' => 'attributed'];
                },

            'static' => static
                fn (): array => ['kind' => 'static'],

            // The first one's keyword shares its line with the attribute the second one starts at.
            'shadowed' => #[OperationId('shadowed')]
                fn (): array => ['kind' => 'shadowed'], 'neighbour' => #[OperationId('neighbour')]
                fn (): array => ['kind' => 'neighbour'],

            // Two keywords on one line, which a line alone cannot tell apart.
            'nested' => fn (): array => array_map(fn (int $n): array => ['kind' => 'inner'], [1]),
        ];
    }
}
