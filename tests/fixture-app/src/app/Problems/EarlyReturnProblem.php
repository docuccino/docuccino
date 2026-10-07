<?php

declare(strict_types=1);

namespace App\Problems;

use Symfony\Component\HttpFoundation\Response;

/** A rendered problem that returns before reading a response that is not an error. */
final class EarlyReturnProblem extends RenderedProblem
{
    public function __construct(Response $rendered)
    {
        if ($rendered->getStatusCode() < 400) {
            return;
        }

        parent::__construct($rendered);
    }
}
