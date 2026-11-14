<?php

declare(strict_types=1);

namespace App\Problems;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

/** An RFC 9457 problem that sends itself, which problems saying more about one family of error extend. */
class SentProblem implements Responsable
{
    public readonly string $type;

    public function __construct(public readonly int $status, public readonly string $title)
    {
        $this->type = 'about:blank';
    }

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse($this, $this->status, ['Content-Type' => 'application/problem+json']);
    }
}
