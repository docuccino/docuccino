<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Concerns\BuildsProbeFailures;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A class with no constructor of its own whose only factory comes from a TRAIT, which is where an
 * application puts the case every one of its exceptions has.
 */
final class ExportThrottledException extends HttpException
{
    use BuildsProbeFailures;
}
