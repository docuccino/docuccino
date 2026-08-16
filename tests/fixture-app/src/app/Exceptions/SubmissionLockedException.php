<?php

declare(strict_types=1);

namespace App\Exceptions;

use Docuccino\Attributes\ErrorComponent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A renderable exception: one exception, one body, and the render method is the outermost hop on its own
 * path — so the attribute sits there whether or not the body is built somewhere else.
 */
final class SubmissionLockedException extends RuntimeException
{
    #[ErrorComponent('SubmissionLocked')]
    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'type' => 'https://portal.example/errors/locked',
            'title' => 'Locked',
            'status' => 423,
        ], 423);
    }
}
