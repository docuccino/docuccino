<?php

declare(strict_types=1);

namespace App\Problems;

use Symfony\Component\HttpFoundation\Response;

/** A rendered problem that always says what went wrong, whatever the response said. */
final class ExplainedProblem extends RenderedProblem
{
    public function __construct(Response $rendered, string $explanation)
    {
        parent::__construct($rendered);

        $this->detail = $explanation;
    }
}
