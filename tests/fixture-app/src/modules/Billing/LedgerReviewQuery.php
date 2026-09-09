<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Exceptions\ManifestRejectedException;

/**
 * A guard written outside the descend scope that raises an exception the application declares inside it —
 * the two halves of "where the code lives" pulled apart.
 */
final class LedgerReviewQuery
{
    /**
     * @return list<string>
     */
    public function results(bool $missing): array
    {
        if ($missing) {
            throw ManifestRejectedException::notFound();
        }

        return ['ledger-1'];
    }

    /**
     * The same guard DECLARING an exception this root also declares, so neither the throw nor the class
     * is inside the descend scope.
     *
     * @return list<string>
     *
     * @throws LedgerRejectedException
     */
    public function declaredResults(bool $missing): array
    {
        if ($missing) {
            throw LedgerRejectedException::notCustom();
        }

        return ['ledger-2'];
    }
}
