<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * One arm, one problem document, and two members it can only ask the failure for. The words it writes out
 * fold; the enum and the instant do not, so the example beside the schema has to be filled at exactly the
 * two members the schema itself says the most about.
 */
final class ExportProblemRenderer
{
    public function __invoke(Throwable $e): ?JsonResponse
    {
        if (! $e instanceof ExportRefusedException) {
            return null;
        }

        return (new ExportProblem(
            type: 'https://example.com/problems/export-refused',
            title: 'Conflict',
            status: 409,
            reason: $e->reason(),
            failedAt: $e->failedAt(),
        ))->toProblemResponse();
    }
}
