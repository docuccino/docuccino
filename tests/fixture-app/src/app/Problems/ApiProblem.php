<?php

declare(strict_types=1);

namespace App\Problems;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A rendered problem that names the request it was raised for, which problems carrying more extend. */
class ApiProblem extends RenderedProblem
{
    public readonly string $instance;

    public function __construct(Response $rendered, Request $request)
    {
        parent::__construct($rendered);

        $this->instance = '/'.$request->path();
    }
}
