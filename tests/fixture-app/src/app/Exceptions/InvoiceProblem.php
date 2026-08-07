<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Phase-4b real-engine fixture: an idiomatic project Problem-Details enum (the shape of a real app's
 * `ProblemType`). It is the single source of truth for a problem's type URI (its BACKING value), HTTP
 * status and human title. The renderer binds a concrete case into {@see ProblemResponse::fromProblem()},
 * whose body reads the case's accessors — proving the enum-accessor folding capability recovers a
 * per-case `const` type URI, status and title for each documented error response.
 *
 * The accessor shapes deliberately exercise every fold the engine must handle:
 *   - `->value` (the backing URI) and `->name` — fold from the case itself (reflection, no body analysis);
 *   - `status()` / `title()` — a `match ($this)` per case, folded by analysing this method's body;
 *   - `docsUrl()` — a plain constant return, folded outright;
 *   - `classify()` — a COMPUTED body (`strtoupper($this->name)`) that does NOT constant-fold, so it stays
 *     honestly permissive (never guessed).
 */
enum InvoiceProblem: string
{
    case Forbidden = 'https://errors.test/problems/forbidden';
    case NotFound = 'https://errors.test/problems/missing';
    case Conflict = 'https://errors.test/problems/conflict';

    public function status(): int
    {
        return match ($this) {
            self::Forbidden => 403,
            self::NotFound => 404,
            self::Conflict => 409,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Forbidden => 'Forbidden',
            self::NotFound => 'Not Found',
            self::Conflict => 'Conflict',
        };
    }

    public function docsUrl(): string
    {
        return 'https://errors.test/docs';
    }

    public function classify(): string
    {
        return strtoupper($this->name);
    }
}
