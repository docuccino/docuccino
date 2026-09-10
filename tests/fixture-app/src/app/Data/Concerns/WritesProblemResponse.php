<?php

declare(strict_types=1);

namespace App\Data\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\LaravelData\Support\Wrapping\WrapExecutionType;

/**
 * The response every problem payload in this app writes, factored into a trait the way an app with more
 * than one of them does. PHP flattens the body into the using class while reflection still names THIS
 * file, so the class looks from the outside exactly like one that never wrote a response at all — and
 * the media type and status spelled out below are what a reader that stops at "another file" throws away.
 */
trait WritesProblemResponse
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(
            data: $this->transform(
                TransformationContextFactory::create()->withWrapExecutionType(WrapExecutionType::Disabled)
            ),
            status: $this->status,
            headers: ['Content-Type' => 'application/problem+json'],
        );
    }
}
