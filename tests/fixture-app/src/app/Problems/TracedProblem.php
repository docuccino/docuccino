<?php

declare(strict_types=1);

namespace App\Problems;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** An api problem carrying the id of the trace it was raised in. */
final class TracedProblem extends ApiProblem
{
    public readonly string $traceId;

    public function __construct(Response $rendered, Request $request, string $traceId)
    {
        parent::__construct($rendered, $request);

        $this->traceId = $traceId;
    }
}
