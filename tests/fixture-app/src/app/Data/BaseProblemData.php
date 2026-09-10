<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\LaravelData\Support\Wrapping\WrapExecutionType;

/**
 * The third place an app can put the same response: a base class every problem payload extends, carrying
 * the fields they share. Unlike a trait, an inherited method is reported as declared HERE, by a class
 * whose own file is the method's file, so it is not mistakable for the vendor's however loosely the
 * question is asked. Only ever analysed.
 */
abstract class BaseProblemData extends Data
{
    public function __construct(
        public string $type,
        public int $status,
    ) {}

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
