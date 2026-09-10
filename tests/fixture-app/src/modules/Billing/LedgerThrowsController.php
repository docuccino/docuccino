<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Exceptions\ManifestRejectedException;

/**
 * An action declared OUTSIDE the descend scope, which is where a modular application writes most of them.
 * The engine analyses whatever action it is handed, so the throws are seen either way — what changes is
 * whether the fold that reads their status is allowed to open the files that state it.
 */
final class LedgerThrowsController
{
    /** The application's own exception, declared inside the descend scope, thrown from outside it. */
    public function appExceptionFromModularAction(): void
    {
        throw ManifestRejectedException::notFound();
    }

    /** Both halves outside: the class and the throw. */
    public function modularExceptionFromModularAction(): void
    {
        throw LedgerRejectedException::notFound();
    }

    /**
     * An `app/` exception constructed HERE with a status chosen at run time. The fold that gave up was
     * reading THIS file, which is outside the descend scope, so the notice is judged unactionable even
     * though the line is the application's own.
     */
    public function dynamicConstructionFromModularAction(int $chosen): void
    {
        throw new \Symfony\Component\HttpKernel\Exception\HttpException($chosen, 'The ledger entry was rejected.');
    }

    /** The same, one undeclared call away inside the same modular root. */
    public function modularExceptionOneCallAway(LedgerReviewQuery $query): void
    {
        $query->results(true);
    }
}
