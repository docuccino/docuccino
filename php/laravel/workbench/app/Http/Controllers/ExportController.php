<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Workbench\App\Enums\ExportFault;
use Workbench\App\Exceptions\ExportFaultException;

/**
 * Refusals whose fault is looked up from its backing value: once from a value written in the code, and
 * once from one the request chooses.
 */
final class ExportController
{
    /** Lock an export. */
    public function lock(string $export): JsonResponse
    {
        throw new ExportFaultException(ExportFault::from(423));
    }

    /** Fail an export with the fault the caller names. */
    public function fail(string $export, int $code): JsonResponse
    {
        throw new ExportFaultException(ExportFault::from($code));
    }
}
