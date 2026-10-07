<?php

declare(strict_types=1);

namespace App\Problems;

use Symfony\Component\HttpFoundation\Response;

/** An RFC 9457 problem whose title is the reason phrase of the status the rendered response carries. */
final class HttpProblem
{
    public readonly string $type;

    public readonly string $title;

    public readonly int $status;

    public function __construct(Response $rendered)
    {
        $this->type = 'about:blank';
        $this->status = $rendered->getStatusCode();
        $this->title = Response::$statusTexts[$this->status] ?? 'Error';
    }
}
