<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A class with no constructor of its own and exactly one factory: every instance it can be asked for is
 * built with the same status, and nothing at a throw site has to say so. Reached here from a trait method
 * that DECLARES it, which is a throw point carrying no construction for a per-site read to fold.
 */
final class ProbeStaleException extends HttpException
{
    public static function make(): self
    {
        return new self(409, 'The probe reading is stale.');
    }
}
