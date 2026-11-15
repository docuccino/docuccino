<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\Validation\FlatValidationException;
use App\Exceptions\Validation\SubmissionInvalidException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/** A failed validation's messages put in a body, by each of the ways an application reads them. */
final class ValidationErrorsController
{
    public function exceptionErrors(ValidationException $e): JsonResponse
    {
        return response()->json(['errors' => $e->errors()], 422);
    }

    public function inheritedErrors(SubmissionInvalidException $e): JsonResponse
    {
        return response()->json(['errors' => $e->errors()], 422);
    }

    public function bagMessages(ValidationException $e): JsonResponse
    {
        return response()->json(['errors' => $e->validator->errors()->messages()], 422);
    }

    public function bagArray(ValidationException $e): JsonResponse
    {
        return response()->json(['errors' => $e->validator->errors()->toArray()], 422);
    }

    public function overriddenErrors(FlatValidationException $e): JsonResponse
    {
        return response()->json(['errors' => $e->errors()], 422);
    }
}
