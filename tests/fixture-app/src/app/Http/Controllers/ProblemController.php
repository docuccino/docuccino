<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\InvoiceProblem;
use App\Exceptions\ProblemResponse;
use Illuminate\Http\JsonResponse;

/**
 * Real-engine fixture: a controller ACTION (not an exception renderer) whose response is built through
 * the enum-driven problem helper. Analysed through the ORCHESTRATED pool, it drops the whole
 * refinement + enum-fold + StatusMarkerT + example-source machinery onto the cross-process determinism
 * invariants (1-vs-N workers, cold-vs-warm cache): the harvested `JsonResponse` return carries the
 * per-case `const` members, the folded 403 status, the `application/problem+json` content type and the
 * status-marker `status` member — exactly the new output the WorkerDeterminismTest must byte-lock.
 */
final class ProblemController
{
    public function forbidden(): JsonResponse
    {
        return ProblemResponse::fromProblem(InvoiceProblem::Forbidden, 'You may not view this invoice.');
    }

    /**
     * The UNFOLDED-status sibling: the status forwarded into the helper is this action's own parameter, so
     * it folds to no literal (recovered permissively) and the body `status` member — reading that same
     * accessor — survives as a status-provenance marker for the response seam to fill. Keeps BOTH refined
     * shapes (folded literal AND status marker) on the pool's determinism invariants, since the
     * concrete-case path above correctly resolves its status to a literal 403.
     */
    public function dynamic(int $code): JsonResponse
    {
        return ProblemResponse::make('about:blank', 'HTTP Error', $code, 'Something went wrong.');
    }
}
