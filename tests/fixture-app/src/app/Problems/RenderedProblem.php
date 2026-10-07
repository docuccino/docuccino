<?php

declare(strict_types=1);

namespace App\Problems;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** An RFC 9457 problem built from a rendered response, which problems for each error family extend. */
class RenderedProblem
{
    public readonly string $type;

    public readonly string $title;

    public readonly int $status;

    /** Only sent when the response said more than its status text. */
    public string $detail;

    public function __construct(Response $rendered)
    {
        $this->type = 'about:blank';
        $this->status = $rendered->getStatusCode();
        $this->title = Response::$statusTexts[$this->status] ?? 'Error';

        $message = $rendered instanceof JsonResponse ? ($rendered->getData(true)['message'] ?? null) : null;
        if (is_string($message) && $message !== '' && $message !== $this->title) {
            $this->detail = $message;
        }
    }
}
