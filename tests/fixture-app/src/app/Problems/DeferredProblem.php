<?php

declare(strict_types=1);

namespace App\Problems;

use Symfony\Component\HttpFoundation\Response;

/** A rendered problem that only reads the response when it is an error. */
final class DeferredProblem extends RenderedProblem
{
    public function __construct(Response $rendered)
    {
        if ($rendered->getStatusCode() >= 400) {
            parent::__construct($rendered);
        }
    }
}
