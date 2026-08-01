<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

/**
 * The single "short class name" helper. Three call sites — throw-frame labels,
 * the engine's self label, and constant-value descriptor rendering — all feed
 * serialized output, so they must short an FQCN identically; a private copy in
 * each risked drift. `Fqcn::short()` is that one implementation.
 */
final class Fqcn
{
    /** The last namespace segment of an FQCN (or the input, if unqualified). */
    public static function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }
}
