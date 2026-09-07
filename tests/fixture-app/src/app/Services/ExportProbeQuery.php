<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ExportConflictException;

/**
 * A collaborator an action receives by method injection, which is where an application writes the guard
 * that raises its own HTTP exception. The `throw` is a call away from the analysed action, so what the
 * notice about it names has to be THIS file and not the controller line that reached it.
 */
final class ExportProbeQuery
{
    /**
     * @return list<string>
     */
    public function results(bool $retryable): array
    {
        if ($retryable) {
            throw ExportConflictException::whenRetryable($retryable);
        }

        return ['export-1'];
    }
}
