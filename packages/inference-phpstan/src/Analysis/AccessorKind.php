<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

/**
 * How a body-member / status value reads off a callee parameter (the value-flow provenance
 * {@see ResponseShapeRefiner} records per member so a call site can fold it):
 *
 *   - {@see Identity}: the value IS the parameter unchanged (`['detail' => $detail]`, `$status`). Folds
 *     when the caller passes a constant-foldable argument; re-homes when it passes its own parameter.
 *   - {@see Value} / {@see Name}: an enum-case parameter's `->value` / `->name` (`$problem->value`). Folds
 *     trivially from the bound case itself — works for vendor enums too, no body analysis.
 *   - {@see Method}: a NO-ARG accessor method on an enum-case parameter (`$problem->status()`,
 *     `$problem->title()`). Folds only for a PROJECT enum, by analysing the method body with `$this`
 *     narrowed to the bound case (a `match ($this)` arm or a plain constant return); anything else stays
 *     honestly permissive.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
enum AccessorKind
{
    case Identity;
    case Value;
    case Name;
    case Method;
}
