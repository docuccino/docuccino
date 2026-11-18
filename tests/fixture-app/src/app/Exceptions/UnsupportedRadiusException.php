<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/** An argument the application refuses with a status of its own, which it renders. */
class UnsupportedRadiusException extends InvalidArgumentException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
