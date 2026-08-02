<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Workbench\App\Http\Requests\StoreWidgetRequest;

/**
 * Exercises the validation integration: a FormRequest-bound store action (recovered from the typed
 * parameter) and an inline `$request->validate([...])` action (recovered by tracing the body).
 */
final class ValidationController
{
    public function store(StoreWidgetRequest $request): JsonResponse
    {
        return response()->json([], 201);
    }
}
