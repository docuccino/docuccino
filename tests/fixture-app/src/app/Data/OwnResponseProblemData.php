<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\LaravelData\Support\Wrapping\WrapExecutionType;

/**
 * A Data class that writes its OWN response instead of letting spatie render one — the shape an app reaches
 * for once it has to pin the media type and disable wrapping on the transformation rather than the
 * response. `toResponse()` and the transformed body must both be read here: the constructor carries the real
 * status and `Content-Type`, and the payload is still this class's schema. Only ever analysed.
 */
class OwnResponseProblemData extends Data
{
    public function __construct(
        public string $type,
        public int $status,
    ) {}

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
