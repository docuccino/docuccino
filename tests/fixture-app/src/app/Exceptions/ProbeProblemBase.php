<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The base an application shares across its problem exceptions, carrying the factory for the case every
 * one of them has. `new static(…)` here builds the SUBCLASS, so this line is a construction of each of
 * them wherever the subclass is written.
 */
abstract class ProbeProblemBase extends HttpException
{
    public static function unavailable(): static
    {
        return new static(503, 'The probe is offline.');
    }
}
